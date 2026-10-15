<?php

declare(strict_types=1);

namespace Onelo\Features;

use Onelo\Http\TransportException;
use Onelo\Onelo;
use Onelo\Version;

/**
 * `$onelo->features` — the public façade. 1:1 with Python's `FeaturesClient`:
 * cache-backed reads (fail-closed to hidden), auto-discovery batch-ping, a
 * multi-user-safe per-user resolve, and ready().
 *
 * PHP runtime adaptation: reads come from a {@see SnapshotStore} that is kept
 * fresh by the long-lived {@see Listener} (SSE/polling), exactly as Python's
 * request handlers read a snapshot maintained by the background thread. When NO
 * listener runs (plain PHP-FPM, no daemon), `autoRefresh` makes the FIRST read
 * of a request do one synchronous GET /poll to populate an in-process store —
 * features still resolve, just refreshed per request rather than streamed.
 */
final class FeaturesClient
{
    private const SDK_PLATFORM = 'php';

    private readonly SnapshotStore $store;
    private DiscoveredNames $discovered;
    private UserSnapshotCache $userCache;
    private ?string $userId = null;
    // Per-request identify() misuse detection (parity with Python _client.py). A
    // single-user process never switches between this many distinct identities
    // this fast; 5 switches / 60s signals per-request identify() in a multi-user
    // backend — an antipattern under a persistent runtime (Octane/Swoole).
    private const IDENTIFY_CHURN_WINDOW_SECONDS = 60.0;
    private const IDENTIFY_CHURN_THRESHOLD = 5;
    /** @var list<float> monotonic-ish timestamps of identity switches, within the window */
    private array $identifySwitches = [];
    private bool $identifyChurnWarned = false;
    private bool $pendingDiscovery = false;
    private bool $refreshedThisRequest = false;
    private bool $shutdownRegistered = false;
    /** @var array<string, true> slugs already sent to batch-ping this process */
    private array $pinged = [];
    /** @var array<string, true> one-time-per-slug gated-misuse warning guard */
    private array $warnedGated = [];

    /**
     * @param bool $autoRefresh lazy per-request GET /poll when no listener keeps
     *                          the store fresh (PHP-FPM without the daemon). Set
     *                          false under Octane/Swoole or with a running Listener.
     */
    public function __construct(
        private readonly Onelo $client,
        ?SnapshotStore $store = null,
        private readonly bool $autoRefresh = true,
    ) {
        $this->store = $store ?? new ArrayStore();
        $this->discovered = new DiscoveredNames();
        $this->userCache = new UserSnapshotCache();
    }

    public function store(): SnapshotStore
    {
        return $this->store;
    }

    /**
     * Set the process-global identity used by the cache-backed feature() path.
     * On a multi-user backend prefer forUser() — see maybeWarnGated().
     *
     * @throws SecretKeyRequired for a non-empty userId when the client holds a
     *                           publishable key (#520); identify(null) always works.
     */
    public function identify(?string $userId): void
    {
        $normalized = ($userId !== null && $userId !== '') ? $userId : null;
        if ($normalized !== null) {
            SecretKeyRequired::assertFor($this->client->key, '$onelo->features->identify($userId)');
        }
        // Count only switches TO a non-null id different from the current one —
        // exactly Python's `switched = user_id != prev and user_id is not None`.
        $switched = $normalized !== $this->userId && $normalized !== null;
        $this->userId = $normalized;
        if (!$switched || $this->identifyChurnWarned) {
            return;
        }
        $now = microtime(true);
        $this->identifySwitches[] = $now;
        $cutoff = $now - self::IDENTIFY_CHURN_WINDOW_SECONDS;
        while ($this->identifySwitches !== [] && $this->identifySwitches[0] < $cutoff) {
            array_shift($this->identifySwitches);
        }
        if (count($this->identifySwitches) >= self::IDENTIFY_CHURN_THRESHOLD) {
            $this->identifyChurnWarned = true;
            $this->identifySwitches = [];
            error_log(
                '[onelo.features] identify() was called with ' . self::IDENTIFY_CHURN_THRESHOLD
                . ' distinct user ids within ' . (int) self::IDENTIFY_CHURN_WINDOW_SECONDS . 's — this looks like '
                . 'per-request identify() in a multi-user backend. Identity is PROCESS-GLOBAL: concurrent requests '
                . 'share one identity and every switch swaps the whole snapshot. Under PHP-FPM (one process per '
                . 'request) this is usually harmless, but under a persistent runtime (Octane/Swoole/RoadRunner) it '
                . 'is an antipattern — resolve per request with forUser($id) instead. Shown once per process.'
            );
        }
    }

    // ── cache-backed reads ────────────────────────────────────────────────────

    /**
     * The Feature for `name`. Cache miss → hidden (fail-closed). First sighting of
     * a slug schedules a debounced batch-ping so the dashboard registry picks it
     * up without an explicit declare().
     */
    public function feature(string $name): Feature
    {
        if ($this->discovered->add($name)) {
            $this->scheduleDiscovery();
        }
        $this->maybeAutoRefresh();
        $state = $this->store->getState($name);
        $status = isset($state['status']) && is_string($state['status']) ? $state['status'] : 'hidden';
        if ($status === 'upsell' || $status === 'greyed') {
            $this->maybeWarnGated($name, $status);
        }

        return Feature::fromWire($name, $state);
    }

    /** Shorthand for feature(name)->isEnabled(). */
    public function isEnabled(string $name): bool
    {
        return $this->feature($name)->isEnabled();
    }

    /**
     * Register feature names with the backend so they appear in the dashboard
     * registry without waiting for code paths to execute. Same debounce contract
     * as feature().
     *
     * @param array<int, string> $names
     */
    public function declare(array $names): void
    {
        if ($this->discovered->addMany($names)) {
            $this->scheduleDiscovery();
        }
    }

    /** @return array<int, string> slugs this process has ever asked about. */
    public function snapshotDiscovered(): array
    {
        return $this->discovered->snapshot();
    }

    /** name→status snapshot (for the Monitor module's flag correlation). @return array<string, string> */
    public function snapshot(): array
    {
        return $this->store->snapshot();
    }

    /**
     * Reset per-request transient state. Call at the START of each request under a
     * PERSISTENT runtime (Laravel Octane / Swoole), where the process outlives the
     * request: without it the lazy auto-refresh polls only once for the whole
     * worker lifetime. Under Octane prefer running a {@see Listener} with
     * autoRefresh=false; if you also want discovery on that runtime, call
     * flushDiscovery() at request end (register_shutdown_function does not fire
     * per Octane request). Plain PHP-FPM never needs this.
     */
    public function resetForRequest(): void
    {
        $this->refreshedThisRequest = false;
        $this->pendingDiscovery = false;
    }

    /**
     * Block until the first snapshot is available (config_version > 0). In the
     * no-daemon fallback this does one synchronous poll; with a listener it waits
     * up to `timeout` seconds for the daemon to populate the shared store.
     */
    public function ready(float $timeout = 1.5): bool
    {
        if ($this->store->configVersion() > 0) {
            return true;
        }
        if ($this->autoRefresh) {
            $this->maybeAutoRefresh(); // one guarded poll — won't double-fetch after feature()

            return $this->store->configVersion() > 0;
        }
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($this->store->configVersion() > 0) {
                return true;
            }
            usleep(50_000);
        }

        return $this->store->configVersion() > 0;
    }

    // ── multi-user-safe per-user resolve ──────────────────────────────────────

    /**
     * Resolve features for a SPECIFIC user_id — the multi-user-safe path (no
     * global identity mutation, no cross-user race). Cached per-user for `ttl`
     * seconds. Fail-closed: a network/HTTP error resolves every feature to hidden.
     *
     * @throws SecretKeyRequired when the client holds a publishable key — a
     *                           per-user call must be signed with the secret key (#520).
     */
    public function forUser(string $userId, float $ttl = 30.0): UserFeatures
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('forUser requires a non-empty user_id');
        }
        SecretKeyRequired::assertFor($this->client->key, '$onelo->features->forUser($userId)');
        $snapshot = $this->userCache->get($userId);
        if ($snapshot === null) {
            $snapshot = $this->resolveUser($userId);
            $this->userCache->put($userId, $snapshot, $ttl);
        }

        return new UserFeatures($userId, $snapshot);
    }

    /**
     * Drop the cached per-user snapshot so the NEXT forUser(userId) re-fetches.
     * Pass null to clear every cached user. Sync + cheap — no network.
     */
    public function invalidateUser(?string $userId = null): void
    {
        if ($userId === null) {
            $this->userCache->clear();
        } else {
            $this->userCache->invalidate($userId);
        }
    }

    // ── discovery flush (debounced to request end) ────────────────────────────

    /**
     * Send any not-yet-pinged discovered slugs to the backend registry. Registered
     * as a shutdown function so all feature() calls in a request coalesce into ONE
     * batch-ping (the PHP analogue of Python's debounced timer). Safe to call
     * directly (e.g. end of a queue job).
     */
    public function flushDiscovery(): void
    {
        if (!$this->pendingDiscovery) {
            return;
        }
        $this->pendingDiscovery = false;
        $fresh = [];
        foreach ($this->discovered->snapshot() as $name) {
            if (!isset($this->pinged[$name])) {
                $this->pinged[$name] = true;
                $fresh[] = $name;
            }
        }
        if ($fresh !== []) {
            $this->fireBatchPing($fresh);
        }
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * Common headers so the backend resolves the SDK platform for discovery /
     * Deploy Access. It reads X-Onelo-Sdk-Platform first, falling back to the
     * User-Agent — we send both. @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'onelo-php/' . Version::VERSION,
            'X-Onelo-Sdk-Platform' => self::SDK_PLATFORM,
        ];
    }

    private function scheduleDiscovery(): void
    {
        $this->pendingDiscovery = true;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'flushDiscovery']);
        }
    }

    private function maybeAutoRefresh(): void
    {
        if (!$this->autoRefresh || $this->refreshedThisRequest) {
            return;
        }
        $this->refreshedThisRequest = true;
        $this->pollOnce();
    }

    /**
     * One synchronous GET /api/sdk/features/poll → replaceAll into the store.
     * Fail-closed: any error leaves the store as-is (empty → everything hidden).
     */
    private function pollOnce(): void
    {
        // The key is NOT in the query string — it travels as the
        // `X-Publishable-Key` request header (ADR-0003, #105), exactly as
        // SseConsumer sends it on the stream. self::headers() carries only the
        // transport headers; the credential is added at the send() call below.
        $params = [
            'since_version' => (string) $this->store->configVersion(),
        ];
        if ($this->client->featureEnvironment !== null) {
            $params['environment'] = $this->client->featureEnvironment;
        }
        if ($this->userId !== null) {
            $params['userId'] = $this->userId;
        }
        $url = $this->client->apiUrl . '/api/sdk/features/poll?' . http_build_query($params);
        try {
            $resp = $this->client->transport->send(
                'GET',
                $url,
                self::headers() + ['X-Publishable-Key' => $this->client->key],
                null,
                $this->client->requestTimeoutMs,
            );
        } catch (TransportException $e) {
            error_log('[onelo.features] poll failed (fail-closed): ' . $e->getMessage());

            return;
        }
        if ($resp->status !== 200) {
            if ($resp->status === 401) {
                error_log('[onelo.features] poll: 401 Unauthorized — check the key');
            } elseif ($resp->status >= 500) {
                error_log('[onelo.features] poll: ' . $resp->status . ' server error');
            }

            return; // 304 (up-to-date) or error — leave store as-is
        }
        $data = json_decode($resp->body, true);
        if (!is_array($data)) {
            return;
        }
        // Dashboard "Discover Features" click (parity with the SSE/daemon path):
        // re-ping the known slug set so the registry refreshes. Checked BEFORE the
        // up_to_date short-circuit because the backend sends discovery_requested
        // alongside {up_to_date:true} with no features payload.
        if (($data['discovery_requested'] ?? false)) {
            $this->pinged = [];
            $this->scheduleDiscovery();
        }
        if (($data['up_to_date'] ?? false) === true || !isset($data['features']) || !is_array($data['features'])) {
            return; // version short-circuit — cache already current
        }
        $version = isset($data['config_version']) && is_int($data['config_version']) ? $data['config_version'] : (int) ($data['config_version'] ?? 0);
        $this->store->replaceAll($data['features'], $version);
    }

    /**
     * One stateless POST /api/sdk/features/resolve for `userId` → {name: state}.
     * Fail-closed: returns [] (→ every feature hidden) on any failure.
     *
     * @return array<string, mixed>
     */
    private function resolveUser(string $userId): array
    {
        if ($this->client->apiUrl === '' || $this->client->key === '') {
            error_log('[onelo.features] forUser: missing apiUrl/key — resolving empty');

            return [];
        }
        $body = [
            'publishableKey' => $this->client->key,
            'userId' => $userId,
            'sdk_platform' => self::SDK_PLATFORM,
            'sdk_version' => Version::VERSION,
        ];
        if ($this->client->featureEnvironment !== null) {
            $body['environment'] = $this->client->featureEnvironment;
        }
        try {
            $resp = $this->client->transport->send(
                'POST',
                $this->client->apiUrl . '/api/sdk/features/resolve',
                ['Content-Type' => 'application/json'] + self::headers(),
                (string) json_encode($body),
                $this->client->requestTimeoutMs,
            );
        } catch (TransportException $e) {
            error_log('[onelo.features] forUser resolve failed (fail-closed → hidden): ' . $e->getMessage());

            return [];
        }
        if ($resp->status < 200 || $resp->status >= 300) {
            error_log('[onelo.features] forUser resolve HTTP ' . $resp->status . ' (fail-closed → hidden)');

            return [];
        }
        $data = json_decode($resp->body, true);
        $feats = (is_array($data) && isset($data['features']) && is_array($data['features'])) ? $data['features'] : [];
        $out = [];
        foreach ($feats as $name => $state) {
            $out[(string) $name] = is_array($state) ? $state : ['status' => is_string($state) ? $state : (string) $state];
        }

        return $out;
    }

    /**
     * POST /api/sdk/features/batch-ping — registers discovered slugs. Always sends
     * X-Onelo-Instance-Id (the TOFU device anchor); the live-key path ignores it.
     *
     * @param array<int, string> $names
     */
    private function fireBatchPing(array $names): void
    {
        if ($this->client->apiUrl === '' || $this->client->key === '' || $names === []) {
            return;
        }
        $body = ['publishableKey' => $this->client->key, 'features' => array_values($names)];
        if ($this->client->featureEnvironment !== null) {
            $body['environment'] = $this->client->featureEnvironment;
        }
        try {
            $resp = $this->client->transport->send(
                'POST',
                $this->client->apiUrl . '/api/sdk/features/batch-ping',
                ['Content-Type' => 'application/json', 'X-Onelo-Instance-Id' => InstanceId::get()] + self::headers(),
                (string) json_encode($body),
                $this->client->requestTimeoutMs,
            );
        } catch (TransportException $e) {
            error_log('[onelo.features] batch-ping failed: ' . $e->getMessage()); // transient — retried next discovery

            return;
        }
        if ($resp->status >= 400) {
            // Usually a bound test key rejecting a different device/app. Surface
            // the backend's reason so discovery-does-nothing is diagnosable.
            error_log('[onelo.features] batch-ping rejected (HTTP ' . $resp->status . '): ' . substr($resp->body, 0, 300));
        }
    }

    private function maybeWarnGated(string $name, string $status): void
    {
        if (getenv('ONELO_SUPPRESS_GATING_WARNING')) {
            return;
        }
        if (isset($this->warnedGated[$name])) {
            return;
        }
        $this->warnedGated[$name] = true;
        error_log(sprintf(
            '[onelo.features] feature("%s") resolved to "%s" under the process-global identity. '
            . 'On a multi-user backend resolve per request instead — '
            . '$onelo->features->forUser($userId)->feature("%s") — so plan gating is evaluated for the '
            . 'actual user, not a shared identity. (Set ONELO_SUPPRESS_GATING_WARNING=1 to silence.)',
            $name,
            $status,
            $name,
        ));
    }
}
