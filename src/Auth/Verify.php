<?php

declare(strict_types=1);

namespace Onelo\Auth;

use Onelo\Http\HttpResponse;
use Onelo\Http\TransportException;
use Onelo\Onelo;
use Onelo\Version;

/**
 * Token verification core — the framework-agnostic primitive. 1:1 with Python's
 * `verify_token_sync` + `_map_response`. Use it directly from ANY framework, a
 * queue worker, a CLI, or no framework at all.
 */
final class Verify
{
    /** Base retry backoff (ms): 100, 200, 400 ... — mirrors Python `_BACKOFF_BASE`. */
    private const BACKOFF_BASE_MS = 100.0;

    /**
     * Parse a Retry-After header (seconds form). Null when absent, invalid, or
     * negative — matching Python's `float(...)`-then-guard contract.
     */
    public static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $trimmed) !== 1) {
            return null;
        }
        $seconds = (float) $trimmed;

        return $seconds >= 0 ? $seconds : null;
    }

    /**
     * Pull a bearer token out of an Authorization header value. Null when absent,
     * not a bearer scheme, or empty. Case-insensitive scheme.
     */
    public static function extractBearerToken(?string $authorization): ?string
    {
        if ($authorization === null || $authorization === '') {
            return null;
        }
        $stripped = trim($authorization);
        if (stripos($stripped, 'bearer ') === 0) {
            $token = trim(substr($stripped, 7));

            return $token !== '' ? $token : null;
        }

        // Header present but not a bearer scheme — treat as missing.
        return null;
    }

    /**
     * Verify a user-supplied access token against the Onelo backend.
     *
     * @throws OneloAuthMissingToken token is empty / whitespace
     * @throws OneloAuthError        client was built with a publishable key
     * @throws OneloAuthInvalidToken backend 401
     * @throws OneloAuthForbidden    backend 403
     * @throws OneloAuthRateLimited  backend 429
     * @throws OneloAuthUnavailable  backend 5xx, network failure, unexpected status
     */
    public static function verifyToken(Onelo $client, string $token): OneloUser
    {
        if (trim($token) === '') {
            throw new OneloAuthMissingToken('token is empty');
        }
        if (!$client->isSecretKey) {
            throw new OneloAuthError(
                'verifyToken requires an Onelo client constructed with secretKey: ...; '
                . 'you supplied a publishable key. Backends must use '
                . "new Onelo(secretKey: 'onelo_sk_live_...')."
            );
        }

        $url = $client->apiUrl . '/api/sdk/auth/user';
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            // Send both — backend prefers the dedicated header but accepts the
            // legacy X-Publishable-Key on older deployments.
            'X-Onelo-Secret-Key' => $client->key,
            'X-Publishable-Key' => $client->key,
            'User-Agent' => 'onelo-php/' . Version::VERSION,
        ];

        try {
            $resp = $client->transport->send('GET', $url, $headers, null, $client->requestTimeoutMs);
        } catch (TransportException $e) {
            throw new OneloAuthUnavailable('network error: ' . $e->getMessage());
        }

        return self::mapResponse($resp);
    }

    /**
     * Map a /api/sdk/auth/user response to an OneloUser or throw the typed error.
     * 1:1 with Python `_map_response`.
     */
    private static function mapResponse(HttpResponse $resp): OneloUser
    {
        $status = $resp->status;
        if ($status === 200) {
            try {
                $payload = $resp->json();
            } catch (\JsonException $e) {
                throw new OneloAuthUnavailable('backend returned 200 with invalid JSON: ' . $e->getMessage());
            }
            // Valid JSON but not an object (null / non-empty list / string) —
            // malformed, not an anonymous user. An empty object `{}` decodes to
            // `[]` (which `array_is_list` calls a list); the `!== []` guard lets
            // it fall through to the no-id check below, matching Python's dict path.
            if (!is_array($payload) || (array_is_list($payload) && $payload !== [])) {
                throw new OneloAuthUnavailable('backend returned 200 with a non-object body');
            }
            $user = OneloUser::fromPayload($payload);
            if ($user->id === '') {
                throw new OneloAuthUnavailable('backend returned 200 without a user id');
            }

            return $user;
        }
        if ($status === 401) {
            throw new OneloAuthInvalidToken('invalid or expired token');
        }
        if ($status === 403) {
            throw new OneloAuthForbidden('token valid but principal forbidden');
        }
        if ($status === 429) {
            // NOT a generic 5xx: retrying immediately makes it worse (the retry
            // loop skips it).
            throw new OneloAuthRateLimited(
                'backend rate-limited auth (429)',
                self::parseRetryAfter($resp->header('Retry-After')),
            );
        }
        if ($status >= 500 && $status < 600) {
            throw new OneloAuthUnavailable("backend returned $status");
        }

        // Any other unexpected status — treat as auth failure conservatively.
        throw new OneloAuthUnavailable("unexpected status $status");
    }

    /**
     * verifyToken + a 5xx retry policy with a wall-clock budget. Exponential
     * backoff (100, 200, 400 ...), bounded by $retryTotalTimeoutMs. A 429 is NOT
     * retried. Mirrors Python's `verify_token_sync`.
     */
    public static function verifyWithRetry(
        Onelo $client,
        string $token,
        int $retryAttempts = 3,
        int $retryTotalTimeoutMs = 2000,
    ): OneloUser {
        if ($retryAttempts < 1) {
            throw new \InvalidArgumentException('retryAttempts must be >= 1');
        }
        if ($retryTotalTimeoutMs <= 0) {
            throw new \InvalidArgumentException('retryTotalTimeout must be > 0');
        }

        $deadline = self::nowMs() + $retryTotalTimeoutMs;
        $lastExc = null;

        for ($attempt = 0; $attempt < $retryAttempts; $attempt++) {
            try {
                return self::verifyToken($client, $token);
            } catch (OneloAuthUnavailable $e) {
                // Only 5xx/network (Unavailable) is retryable. 401/403/missing are
                // NOT OneloAuthUnavailable, so they propagate immediately.
                $lastExc = $e;
                if ($e instanceof OneloAuthRateLimited) {
                    break;
                }
                $next = $attempt + 1;
                if ($next >= $retryAttempts) {
                    break;
                }
                $backoff = self::BACKOFF_BASE_MS * (2 ** $attempt); // 100, 200, 400
                $remaining = $deadline - self::nowMs();
                if ($remaining <= 0) {
                    break;
                }
                usleep((int) (min($backoff, $remaining) * 1000)); // ms → µs
                if (self::nowMs() >= $deadline) {
                    break;
                }
            }
        }

        // The loop only exits via `return` (success) or after setting $lastExc.
        throw $lastExc;
    }

    /** Monotonic milliseconds (immune to wall-clock jumps). */
    private static function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
