<?php

declare(strict_types=1);

namespace Onelo\Features;

use Onelo\Version;

/**
 * Polling consumer — opt-in fallback when SSE is unwanted/unavailable. 1:1 port
 * of Python's `onelo._polling.PollingConsumer`, adapted to PHP's blocking model.
 *
 * Periodically GETs /api/sdk/features/poll?since_version=N and writes the snapshot
 * into the shared {@see SnapshotStore}. The backend returns 304 when nothing
 * changed (cheap) and 200 with a new snapshot when something did. Network errors
 * are logged and retried on the next interval — they never crash the loop.
 *
 * Blocking-model note: Python sleeps the interval on the event loop and races it
 * against a stop event. Here the loop uses a blocking curl GET plus an
 * interruptible sleep that checks the stop flag every ~1s, so stop() is honoured
 * within a second rather than after a full interval.
 */
final class PollingConsumer
{
    private readonly string $apiUrl;
    private readonly ?\Closure $onDiscoveryRequested;
    private readonly ?\Closure $onFirstContact;

    /** Set once the backend has answered a poll (200 or 304). */
    private bool $firstContact = false;
    /** Set by stop(); checked between/inside the sleep interval. */
    private bool $stopped = false;

    /**
     * @param SnapshotStore $store               shared snapshot the web workers read.
     * @param string        $key                 publishable/secret key.
     * @param string        $apiUrl              backend base URL.
     * @param float         $pollInterval        seconds between polls (default 30).
     * @param int           $requestTimeoutMs    per-request timeout.
     * @param string|null   $featureEnvironment  'test' | 'live' | null.
     * @param string|null   $userId              optional FIXED single-tenant user id.
     * @param callable|null $onDiscoveryRequested invoked when the backend flags a pending discovery.
     * @param callable|null $onFirstContact       invoked once, on the first successful poll.
     */
    public function __construct(
        private readonly SnapshotStore $store,
        private readonly string $key,
        string $apiUrl,
        private readonly float $pollInterval = 30.0,
        private readonly int $requestTimeoutMs = 5000,
        private readonly ?string $featureEnvironment = null,
        private readonly ?string $userId = null,
        ?callable $onDiscoveryRequested = null,
        ?callable $onFirstContact = null,
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->onDiscoveryRequested = $onDiscoveryRequested !== null ? \Closure::fromCallable($onDiscoveryRequested) : null;
        $this->onFirstContact = $onFirstContact !== null ? \Closure::fromCallable($onFirstContact) : null;
    }

    /** True once the backend has answered at least one poll. */
    public function firstContact(): bool
    {
        return $this->firstContact;
    }

    /** Signal the loop to exit. Honoured within ~1s (checked during the sleep). */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /** Blocking poll loop until stop(). 1:1 with Python `PollingConsumer.run`. */
    public function run(): void
    {
        while (!$this->stopped) {
            $this->pollOnce();
            $this->interruptibleSleep($this->pollInterval);
        }
    }

    /** One GET /features/poll → replaceAll into the store. Never throws. */
    private function pollOnce(): void
    {
        $params = [
            'key' => $this->key,
            'since_version' => (string) $this->store->configVersion(),
        ];
        if ($this->featureEnvironment !== null) {
            $params['environment'] = $this->featureEnvironment;
        }
        if ($this->userId !== null) {
            $params['userId'] = $this->userId;
        }
        $url = $this->apiUrl . '/api/sdk/features/poll?' . http_build_query($params);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('[onelo.features] poll: failed to initialise curl');

            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: onelo-php/' . Version::VERSION,
            ],
            CURLOPT_TIMEOUT_MS => $this->requestTimeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->requestTimeoutMs,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        // No curl_close(): no-op since PHP 8.0, DEPRECATED since 8.5 (the notice
        // leaks into responses under display_errors=1). GC frees $ch. See CurlTransport.

        if ($body === false) {
            error_log('[onelo.features] poll request failed: ' . $err); // try again next interval

            return;
        }

        // 304 = cache up-to-date; still counts as first contact for ready().
        if ($status === 200 || $status === 304) {
            $this->markFirstContact();
        }

        if ($status === 200) {
            $data = json_decode((string) $body, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                error_log('[onelo.features] poll response not JSON: ' . json_last_error_msg());

                return;
            }
            // A valid-JSON-but-non-object body (a stray proxy/captive-portal array
            // or string) must not crash the loop — bail like Python's TypeError path.
            if (!is_array($data)) {
                error_log('[onelo.features] poll response not a JSON object');

                return;
            }
            // Version short-circuit: when since_version matches, the backend
            // returns {config_version, up_to_date: true, discovery_requested}
            // with NO `features` payload. The store is already current — no-op.
            if (($data['up_to_date'] ?? false) === true || !isset($data['features']) || !is_array($data['features'])) {
                $this->maybeRequestDiscovery($data);

                return;
            }
            $version = $data['config_version'] ?? null;
            if (!is_int($version) && !(is_string($version) && is_numeric($version))) {
                error_log('[onelo.features] poll response malformed: missing/invalid config_version');

                return;
            }
            // Keep the full per-feature state dicts (status + upsell metadata);
            // the store normalises and copies each value.
            $this->store->replaceAll($data['features'], (int) $version);
            $this->maybeRequestDiscovery($data);
        } elseif ($status === 401) {
            error_log('[onelo.features] poll: 401 Unauthorized — check the key');
        } elseif ($status >= 500 && $status < 600) {
            error_log('[onelo.features] poll: ' . $status . ' server error');
        }
    }

    /**
     * Fire the discovery callback when the backend flags a pending discovery
     * request (dashboard 'Discover Features' click). Parity with the SSE
     * `discovery_requested` event.
     *
     * @param array<string, mixed> $data
     */
    private function maybeRequestDiscovery(array $data): void
    {
        if (($data['discovery_requested'] ?? false) && $this->onDiscoveryRequested !== null) {
            try {
                ($this->onDiscoveryRequested)();
            } catch (\Throwable $e) {
                error_log('[onelo.features] discovery_requested handler failed: ' . $e->getMessage());
            }
        }
    }

    /** Record first contact and invoke the ready callback exactly once. */
    private function markFirstContact(): void
    {
        if ($this->firstContact) {
            return;
        }
        $this->firstContact = true;
        if ($this->onFirstContact !== null) {
            try {
                ($this->onFirstContact)();
            } catch (\Throwable $e) {
                error_log('[onelo.features] onFirstContact handler failed: ' . $e->getMessage());
            }
        }
    }

    /** Sleep up to $seconds, waking early on stop(). Dispatches pcntl signals. */
    private function interruptibleSleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $deadline = microtime(true) + $seconds;
        while (!$this->stopped) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return;
            }
            // 1s steps are fine — the interval is coarse-grained (default 30s).
            $step = $remaining < 1.0 ? $remaining : 1.0;
            usleep((int) ($step * 1_000_000));
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
}
