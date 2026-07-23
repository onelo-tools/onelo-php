<?php

declare(strict_types=1);

namespace Onelo\Auth;

use Onelo\Onelo;

/**
 * The shared auth resolver — the logic every framework adapter runs:
 * extract token → cache → verify(+retry) → gates → error-mapping. So the Laravel
 * and PSR-15 adapters stay thin. Mirrors the per-request `_resolve` shared by
 * Python's `onelo.fastapi` / `onelo.flask`, including the exact 401/403/503
 * mapping and `auth.*` events.
 */
final class Resolver
{
    private AuthCache $cache;
    private float $cacheTtl;
    private bool $acceptQueryToken;
    private bool $requireEmailVerified;
    /** @var array<string, int>|null flip of requirePlan for O(1) membership */
    private ?array $planSet;
    private int $retryAttempts;
    private int $retryTotalTimeoutMs;
    /** @var (callable(?string, string): void)|null */
    private $onAuthEvent;
    private bool $identifyMonitor;

    /**
     * @param string[]|null                     $requirePlan
     * @param (callable(?string, string): void)|null $onAuthEvent
     */
    public function __construct(
        private readonly Onelo $client,
        ?AuthCache $cache = null,
        float $cacheTtl = 30.0,
        bool $acceptQueryToken = false,
        bool $requireEmailVerified = false,
        ?array $requirePlan = null,
        int $retryAttempts = 3,
        int $retryTotalTimeoutMs = 2000,
        ?callable $onAuthEvent = null,
        bool $identifyMonitor = true,
    ) {
        if (!$client->isSecretKey) {
            throw new \InvalidArgumentException(
                'This adapter requires an Onelo client constructed with secretKey: ...; '
                . 'you supplied a publishable key.'
            );
        }
        if ($retryAttempts < 1) {
            throw new \InvalidArgumentException('retryAttempts must be >= 1');
        }
        if ($retryTotalTimeoutMs <= 0) {
            throw new \InvalidArgumentException('retryTotalTimeout must be > 0');
        }
        $this->cache = $cache ?? new InMemoryAuthCache();
        $this->cacheTtl = $cacheTtl;
        $this->acceptQueryToken = $acceptQueryToken;
        $this->requireEmailVerified = $requireEmailVerified;
        $this->planSet = ($requirePlan !== null && count($requirePlan) > 0) ? array_flip($requirePlan) : null;
        $this->retryAttempts = $retryAttempts;
        $this->retryTotalTimeoutMs = $retryTotalTimeoutMs;
        $this->onAuthEvent = $onAuthEvent;
        $this->identifyMonitor = $identifyMonitor;
    }

    /**
     * Build from an options array (used by the adapters). Recognised keys:
     * cache, cacheTtl, acceptQueryToken, requireEmailVerified, requirePlan,
     * retryAttempts, retryTotalTimeoutMs, onAuthEvent, identifyMonitor.
     *
     * @param array<string, mixed> $o
     */
    public static function fromOptions(Onelo $client, array $o = []): self
    {
        return new self(
            $client,
            cache: $o['cache'] ?? null,
            cacheTtl: $o['cacheTtl'] ?? 30.0,
            acceptQueryToken: $o['acceptQueryToken'] ?? false,
            requireEmailVerified: $o['requireEmailVerified'] ?? false,
            requirePlan: $o['requirePlan'] ?? null,
            retryAttempts: $o['retryAttempts'] ?? 3,
            retryTotalTimeoutMs: $o['retryTotalTimeoutMs'] ?? 2000,
            onAuthEvent: $o['onAuthEvent'] ?? null,
            identifyMonitor: $o['identifyMonitor'] ?? true,
        );
    }

    private function emit(?string $userId, string $event): void
    {
        if ($this->onAuthEvent === null) {
            return;
        }
        try {
            ($this->onAuthEvent)($userId, $event);
        } catch (\Throwable $e) {
            // A callback must NEVER take down the request — log then swallow.
            error_log('[onelo] onAuthEvent callback threw; suppressing: ' . $e->getMessage());
        }
    }

    private function checkGates(OneloUser $user): void
    {
        if ($this->requireEmailVerified && !$user->emailVerified) {
            throw new OneloAuthForbidden('email_unverified');
        }
        if ($this->planSet !== null && !($user->plan !== null && isset($this->planSet[$user->plan]))) {
            throw new OneloAuthForbidden('plan');
        }
    }

    /**
     * Attach the verified user's id to the monitor scope so errors captured
     * during the request carry the user. Only fires when the scope is per-request
     * isolated — PHP-FPM (one process per request) or after the monitor middleware
     * has resetScope()'d — so on a persistent runtime (Octane/Swoole) WITHOUT the
     * monitor middleware it safely no-ops instead of leaking the user across
     * requests. Parity with Python's `identify_monitor` (feature; PHP isolation
     * mechanism differs).
     */
    private function bridgeMonitor(OneloUser $user): void
    {
        if ($this->identifyMonitor && $user->id !== '' && $this->client->monitor->isRequestScopeIsolated()) {
            $this->client->monitor->setUser(['id' => $user->id]);
        }
    }

    private function extractToken(?string $authorization, ?string $queryToken): ?string
    {
        // Header present (even if not bearer) → use it exclusively; don't fall
        // through to the query param. Deliberately follows FastAPI's
        // `_extract_token` (and the Node SDK) — the more secure choice — rather
        // than Flask's fall-through-on-non-bearer, so all Onelo backend SDKs agree.
        if ($authorization !== null && $authorization !== '') {
            return Verify::extractBearerToken($authorization);
        }
        if ($this->acceptQueryToken && $queryToken !== null && $queryToken !== '') {
            $t = trim($queryToken);

            return $t !== '' ? $t : null;
        }

        return null;
    }

    /**
     * Resolve a request to a verified {@see OneloUser} or throw {@see AuthHttpError}.
     *
     * @throws AuthHttpError
     */
    public function resolve(?string $authorization, ?string $queryToken = null): OneloUser
    {
        $token = $this->extractToken($authorization, $queryToken);
        if ($token === null) {
            $this->emit(null, 'auth.fail.missing_token');
            throw new AuthHttpError(401, ['error' => 'missing_token']);
        }

        $hash = hash('sha256', $token);

        // Cache lookup BEFORE any network work.
        $cached = $this->cache->get($hash);
        if ($cached !== null) {
            try {
                $this->checkGates($cached);
            } catch (OneloAuthForbidden $e) {
                $this->emit($cached->id !== '' ? $cached->id : null, 'auth.fail.' . $e->getMessage());
                throw new AuthHttpError(403, ['error' => 'forbidden', 'reason' => $e->getMessage()]);
            }
            $this->emit($cached->id, 'auth.verify');
            $this->bridgeMonitor($cached);

            return $cached;
        }

        // Miss — verify, with retries on 5xx.
        try {
            $user = Verify::verifyWithRetry($this->client, $token, $this->retryAttempts, $this->retryTotalTimeoutMs);
        } catch (OneloAuthMissingToken $e) {
            $this->emit(null, 'auth.fail.missing_token');
            throw new AuthHttpError(401, ['error' => 'missing_token']);
        } catch (OneloAuthInvalidToken $e) {
            $this->emit(null, 'auth.fail.invalid_token');
            throw new AuthHttpError(401, ['error' => 'invalid_token']);
        } catch (OneloAuthForbidden $e) {
            $this->emit(null, 'auth.fail.forbidden');
            throw new AuthHttpError(403, ['error' => 'forbidden']);
        } catch (OneloAuthUnavailable $e) {
            // OneloAuthRateLimited is a subclass of Unavailable → also 503 here.
            $this->emit(null, 'auth.fail.unavailable');
            throw new AuthHttpError(503, ['error' => 'auth_service_unavailable']);
        } catch (OneloAuthError $e) {
            // Catch-all for any future OneloAuthError subclass.
            $this->emit(null, 'auth.fail.invalid_token');
            throw new AuthHttpError(401, ['error' => 'invalid_token']);
        }

        // Cache the raw verification result; gates are per-request.
        $this->cache->set($hash, $user, $this->cacheTtl);

        try {
            $this->checkGates($user);
        } catch (OneloAuthForbidden $e) {
            $this->emit($user->id !== '' ? $user->id : null, 'auth.fail.' . $e->getMessage());
            throw new AuthHttpError(403, ['error' => 'forbidden', 'reason' => $e->getMessage()]);
        }

        $this->emit($user->id, 'auth.verify');
        $this->bridgeMonitor($user);

        return $user;
    }
}
