<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * Propagate monitor scope across a process / queue boundary. A queued job (a
 * Laravel queue worker, a Symfony Messenger handler) runs outside the request
 * that scheduled it, so the user / tags / breadcrumbs on that request's scope
 * never reach the worker. The producer calls {@see MonitorClient::carrier()} and
 * attaches the result to the job; the worker wraps its body in
 * {@see MonitorClient::continueTrace()} to restore the scope.
 *
 * 1:1 with Python's `onelo.monitor._propagation` (carrier / continue_trace):
 *   - Privacy: only `user.id` is forwarded (never email / metadata — the carrier
 *     lands in the broker payload, possibly persisted). Tags / contexts /
 *     breadcrumbs run through the same PII scrubber as the wire transport.
 *   - Integrity: pass a shared `$key` (your publishable key — producer and worker
 *     both have it) to HMAC-SHA256-sign the carrier so a different tenant on the
 *     same broker can't forge a `user.id`. Without a key the carrier is unsigned
 *     and accepted best-effort (single-tenant back-compat).
 */
final class Propagation
{
    private const HMAC_PAYLOAD_KEY = '_data';
    private const HMAC_SIG_KEY = '_sig';

    /**
     * Serialise a scope into a JSON-safe carrier (optionally HMAC-signed).
     *
     * @return array<string, mixed>
     */
    public static function build(Scope $scope, bool $includeBreadcrumbs, ?string $key): array
    {
        // 32 hex chars — same shape as Python's uuid4().hex — for dashboard correlation.
        $out = ['trace_id' => bin2hex(random_bytes(16))];

        $user = $scope->getUser();
        if (is_array($user)) {
            // Whitelist: id ONLY. Email / metadata stay on-host.
            $uid = $user['id'] ?? null;
            if ($uid !== null) {
                $out['user'] = ['id' => (string) $uid];
            }
        }
        $tags = $scope->getTags();
        if ($tags !== []) {
            $scrubbed = Scrub::scrubMeta($tags);
            if (is_array($scrubbed) && $scrubbed !== []) {
                $out['tags'] = $scrubbed;
            }
        }
        $contexts = $scope->getContexts();
        if ($contexts !== []) {
            $scrubbed = Scrub::scrubMeta($contexts);
            if (is_array($scrubbed) && $scrubbed !== []) {
                $out['contexts'] = $scrubbed;
            }
        }
        if ($includeBreadcrumbs) {
            $crumbs = array_map(static fn (Breadcrumb $c): array => $c->toWire(), $scope->getBreadcrumbs());
            if ($crumbs !== []) {
                // Defense in depth — breadcrumb urls/values already scrub at
                // construction; re-scrub the serialised list before it leaves.
                $scrubbed = Scrub::scrubMeta(['_' => $crumbs]);
                if (is_array($scrubbed) && isset($scrubbed['_']) && is_array($scrubbed['_'])) {
                    $out['breadcrumbs'] = $scrubbed['_'];
                }
            }
        }

        if ($key !== null && $key !== '') {
            return [self::HMAC_PAYLOAD_KEY => $out, self::HMAC_SIG_KEY => self::sign($out, $key)];
        }

        return $out;
    }

    /**
     * Return the trusted carrier payload, or null if rejected. Acceptance rules
     * mirror Python's `_unwrap_carrier`:
     *   - null carrier                                  → null (legacy job)
     *   - no key                                        → trust as-is (unless it
     *                                                     LOOKS signed → reject:
     *                                                     can't verify a signed one)
     *   - key + unsigned carrier                        → reject (producer must sign)
     *   - key + signed carrier + HMAC matches           → unwrap and trust
     *   - key + signed carrier + HMAC mismatch          → reject
     *
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>|null
     */
    public static function unwrap(?array $data, ?string $key): ?array
    {
        if ($data === null) {
            return null;
        }
        $looksSigned = array_key_exists(self::HMAC_SIG_KEY, $data) && array_key_exists(self::HMAC_PAYLOAD_KEY, $data);

        if ($key === null || $key === '') {
            if ($looksSigned) {
                error_log('[onelo.monitor] continueTrace received a signed carrier but no key was supplied to verify it; rejecting');

                return null;
            }

            return $data;
        }

        if (!$looksSigned) {
            error_log('[onelo.monitor] continueTrace was given a key but the carrier is not signed; rejecting (producer must use carrier(key: ...))');

            return null;
        }
        $verified = self::verify($data, $key);
        if ($verified === null) {
            error_log('[onelo.monitor] continueTrace HMAC verification failed; rejecting');
        }

        return $verified;
    }

    /**
     * Apply a trusted carrier payload onto a scope. Mirrors Python's
     * `_apply_carrier` (PHP scope has no `transaction`, so that field is skipped).
     *
     * @param array<string, mixed> $data
     */
    public static function apply(Scope $scope, array $data): void
    {
        $user = $data['user'] ?? null;
        if (is_array($user)) {
            $scope->setUser($user);
        }
        $tags = $data['tags'] ?? null;
        if (is_array($tags)) {
            foreach ($tags as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $scope->setTag((string) $k, (string) $v);
                }
            }
        }
        $contexts = $data['contexts'] ?? null;
        if (is_array($contexts)) {
            foreach ($contexts as $k => $v) {
                if (is_array($v)) {
                    $scope->setContext((string) $k, $v);
                }
            }
        }
        $traceId = $data['trace_id'] ?? null;
        if (is_string($traceId)) {
            $scope->setTag('trace_id', $traceId);
        }
        $breadcrumbs = $data['breadcrumbs'] ?? null;
        if (is_array($breadcrumbs)) {
            foreach ($breadcrumbs as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $scope->addBreadcrumb(new Breadcrumb(
                    (string) ($c['category'] ?? 'info'),
                    (string) ($c['message'] ?? ''),
                    (float) ($c['ts'] ?? 0),
                    (string) ($c['level'] ?? 'info'),
                    isset($c['data']) && is_array($c['data']) ? $c['data'] : null,
                ));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function sign(array $data, string $key): string
    {
        return hash_hmac('sha256', self::canonical($data), $key);
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>|null
     */
    private static function verify(array $envelope, string $key): ?array
    {
        $data = $envelope[self::HMAC_PAYLOAD_KEY] ?? null;
        $sig = $envelope[self::HMAC_SIG_KEY] ?? null;
        if (!is_array($data) || !is_string($sig)) {
            return null;
        }
        $expected = self::sign($data, $key);

        return hash_equals($expected, $sig) ? $data : null;
    }

    /**
     * Canonical JSON — recursively key-sorted, compact, unescaped slashes — so
     * sign and verify agree byte-for-byte. Mirrors Python's
     * `json.dumps(sort_keys=True, separators=(",", ":"))`, so a same-app producer
     * and worker agree on the HMAC (the guaranteed case is PHP→PHP; the Python
     * canonical match is a best-effort bonus for a mixed-language broker).
     */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        // Associative maps are key-sorted (mirrors sort_keys=True); lists keep order.
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortKeys($v);
        }
        if (!$isList) {
            ksort($out);
        }

        return $out;
    }
}
