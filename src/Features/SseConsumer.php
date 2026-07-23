<?php

declare(strict_types=1);

namespace Onelo\Features;

use Onelo\Version;

/**
 * SSE consumer — the default real-time strategy. 1:1 port of Python's
 * `onelo._sse.SSEConsumer`, adapted to PHP's blocking, single-threaded model.
 *
 * Opens a long-lived GET on /api/sdk/features/stream, parses Server-Sent Events,
 * and writes the snapshot into the shared {@see SnapshotStore} on `connected` and
 * `features_updated` events. Reconnects with exponential backoff + full jitter on
 * disconnect or error. Forward-compatible: unknown event types are ignored, so
 * adding new event kinds on the backend doesn't break older SDKs.
 *
 * ── How this differs from the Python source (blocking model deviations) ──
 *
 * 1. No asyncio. Python holds the stream in an `async for` and races each event
 *    against a reconnect signal. PHP has no event loop here — instead we open a
 *    BLOCKING curl handle and register a CURLOPT_WRITEFUNCTION that parses SSE
 *    frames incrementally out of a byte buffer (frames split on "\n\n"; lines
 *    parsed as `event:` / `data:`; a line starting with `:` is a heartbeat
 *    comment). curl_exec() blocks until the server closes the stream, an error
 *    occurs, or our write callback returns short to abort.
 *
 * 2. Zombie / stall detection replaces Python's `_healthcheck_loop`. A separate
 *    watchdog thread is impossible in blocking single-threaded PHP, so we lean on
 *    curl itself: CURLOPT_LOW_SPEED_LIMIT=1 + CURLOPT_LOW_SPEED_TIME=90 make curl
 *    abort a stream that transfers < 1 byte per 90 seconds. The server heartbeats
 *    (`:` comments) every ~30s, so 90s == 3 missed heartbeats — long enough to
 *    absorb a GC/packet-loss blip, short enough that a NAT-killed silent socket
 *    recovers in under two minutes. On abort curl_exec() returns an error and the
 *    outer loop reconnects. This is the same intent as Python's `STALE_THRESHOLD`.
 *
 * 3. force_reconnect() / identify() are N/A here. The daemon streams the GLOBAL
 *    snapshot — there is no per-request user to re-`identify()` — and there is no
 *    second thread that could call force_reconnect() while curl_exec() blocks. So
 *    there is no reconnect-signal machinery; only stop() (checked between
 *    reconnects and inside the write callback). A fixed single-tenant `$userId`
 *    may still be supplied at construction for a single-user deployment.
 *
 * A 401/403 response is TERMINAL (mirrors Python's `_SSETerminalAuthError`): a
 * dead/revoked key never becomes valid by retrying, so we log ONCE (loudly) and
 * stop, instead of storming the backend with reconnects.
 */
final class SseConsumer
{
    /** Base backoff sequence (seconds). delay = uniform(0, base) — full jitter. */
    private const BACKOFF_SECONDS = [1, 2, 4, 8, 16, 30];

    /**
     * Zombie detection knobs. Server emits a `:` heartbeat every ~30s so 90s
     * equals 3 missed heartbeats. Enforced by curl's low-speed abort (see the
     * class docstring), which replaces Python's healthcheck thread.
     */
    private const LOW_SPEED_LIMIT_BYTES = 1;
    private const LOW_SPEED_TIME_SECONDS = 90;

    private readonly string $apiUrl;
    private readonly int $connectTimeoutMs;
    private readonly ?\Closure $onDiscoveryRequested;
    private readonly ?\Closure $onFirstContact;

    /** Set once the backend confirms first contact (any snapshot / up_to_date). */
    private bool $firstContact = false;
    /** Set by stop(); checked between reconnects and inside the write callback. */
    private bool $stopped = false;

    /** Incremental SSE frame buffer, fed by the curl write callback. */
    private string $buffer = '';
    /** HTTP status of the in-flight response, captured by the header callback. */
    private int $currentStatus = 0;
    /** X-Fallback header of the in-flight response (backend sends "poll" on a 429 cap). */
    private string $currentFallback = '';

    /**
     * @param SnapshotStore         $store               shared snapshot the web workers read.
     * @param string                $key                 publishable/secret key.
     * @param string                $apiUrl              backend base URL (no trailing slash needed).
     * @param int                   $connectTimeoutMs    connect timeout; the stream itself has NO
     *                                                   overall timeout (it is long-lived).
     * @param string|null           $featureEnvironment  'test' | 'live' | null.
     * @param string|null           $appVersion          optional app version reported to the backend.
     * @param string|null           $userId              optional FIXED single-tenant user id (daemon
     *                                                    default: null — streams the global snapshot).
     * @param callable|null         $onDiscoveryRequested invoked on a `discovery_requested` event.
     * @param callable|null         $onFirstContact       invoked once, on the first snapshot/up_to_date.
     */
    public function __construct(
        private readonly SnapshotStore $store,
        private readonly string $key,
        string $apiUrl,
        int $connectTimeoutMs = 5000,
        private readonly ?string $featureEnvironment = null,
        private readonly ?string $appVersion = null,
        private readonly ?string $userId = null,
        ?callable $onDiscoveryRequested = null,
        ?callable $onFirstContact = null,
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->onDiscoveryRequested = $onDiscoveryRequested !== null ? \Closure::fromCallable($onDiscoveryRequested) : null;
        $this->onFirstContact = $onFirstContact !== null ? \Closure::fromCallable($onFirstContact) : null;
    }

    /** True once the backend has confirmed first contact (for the Listener's "ready" log). */
    public function firstContact(): bool
    {
        return $this->firstContact;
    }

    /**
     * Signal the loop to exit. Takes effect after the current connection closes:
     * checked between reconnects and inside the write callback (so a live stream
     * aborts on the next byte/heartbeat rather than hanging up to 90s).
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Blocking run loop. Holds an SSE connection, applies snapshots, reconnects
     * with backoff, and returns only when stop() is called or a terminal auth
     * error occurs. 1:1 with Python `SSEConsumer.run`.
     */
    public function run(): void
    {
        $attempt = 0;
        while (!$this->stopped) {
            try {
                $this->connectAndConsume();
                $attempt = 0; // clean close — reset backoff
            } catch (SseTerminalAuthError $exc) {
                // Dead/revoked key — retrying can never succeed. Log ONCE (loudly)
                // and stop, so we don't storm the backend with reconnects.
                error_log(
                    '[onelo.features] SSE stream stopped: authentication failed (HTTP ' . $exc->status . '). '
                    . 'The publishable/secret key is invalid or revoked — fix the key and restart the listener. '
                    . 'Not retrying (a dead credential will not become valid).'
                );

                return;
            } catch (\RuntimeException $exc) {
                // Transient network/curl error — reconnect with backoff.
                error_log('[onelo.features] SSE connection failed: ' . $exc->getMessage());
            }
            if ($this->stopped) {
                break;
            }
            // Full jitter (AWS architecture blog pattern): random delay in
            // [0, base] instead of the fixed base. Same scheme as Swift's
            // OneloFeatures — spreads reconnects across the window so a shared
            // dependency blip doesn't make N workers re-collide on every retry.
            $base = self::BACKOFF_SECONDS[min($attempt, count(self::BACKOFF_SECONDS) - 1)];
            $delay = $base * (mt_rand() / mt_getrandmax());
            $attempt++;
            $this->interruptibleSleep($delay);
        }
    }

    /**
     * Open one SSE connection and consume it until it closes / errors / stop().
     * Throws {@see SseTerminalAuthError} on 401/403 (terminal), or a plain
     * RuntimeException on a transient network/curl error (retried by run()).
     */
    private function connectAndConsume(): void
    {
        $params = [
            'key' => $this->key,
            // Read fresh each handshake so a reconnect resumes from the version
            // the store already has (backend sends `up_to_date` if unchanged).
            'since_version' => (string) $this->store->configVersion(),
            'instance_id' => InstanceId::get(),
            'sdk_platform' => 'php',
            'sdk_version' => Version::VERSION,
        ];
        if ($this->appVersion !== null && $this->appVersion !== '') {
            $params['app_version'] = $this->appVersion;
        }
        if ($this->featureEnvironment !== null) {
            $params['environment'] = $this->featureEnvironment;
        }
        if ($this->userId !== null) {
            $params['userId'] = $this->userId;
        }
        $url = $this->apiUrl . '/api/sdk/features/stream?' . http_build_query($params);

        $this->buffer = '';
        $this->currentStatus = 0;
        $this->currentFallback = '';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('failed to initialise curl for SSE stream');
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
                'User-Agent: onelo-php/' . Version::VERSION,
            ],
            // Connect timeout applies to the handshake only. The stream is
            // long-lived: CURLOPT_TIMEOUT=0 disables the overall timeout so we
            // don't tear down a healthy idle-between-heartbeats connection.
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT => 0,
            // Zombie detection: abort if < 1 byte / 90s (3 missed heartbeats).
            // This is the PHP stand-in for Python's healthcheck thread.
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BYTES,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_SECONDS,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line): int {
                if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
                    $this->currentStatus = (int) $m[1];
                } elseif (stripos($line, 'x-fallback:') === 0) {
                    $this->currentFallback = trim(substr($line, strlen('x-fallback:')));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($_ch, string $chunk): int {
                // Returning a value != strlen($chunk) makes curl abort the
                // transfer (CURLE_WRITE_ERROR). We use that for graceful stop().
                if ($this->stopped) {
                    return 0;
                }
                // On an error response (401/403/5xx) the body is not SSE — don't
                // feed it to the frame parser. Consume it so curl finishes and
                // run() sees the status; the terminal check happens after exec.
                if ($this->currentStatus >= 400) {
                    return strlen($chunk);
                }
                $this->buffer .= $chunk;
                $this->drainBuffer();

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        // No curl_close(): no-op since PHP 8.0, DEPRECATED since 8.5 (the notice
        // leaks into responses under display_errors=1). GC frees $ch. See CurlTransport.

        switch (self::classifyResult($ok !== false, $status, $this->stopped)) {
            case 'terminal':
                // 401/403 → the key is dead; never retried (checked first so a
                // bad key never enters the reconnect loop).
                throw new SseTerminalAuthError($status);
            case 'stop':
                return; // we aborted the write callback for a graceful stop()
            case 'retry':
                // A completed transfer with a NON-200 status (429 connection-cap,
                // 404, 5xx) is an HTTP ERROR whose body we did NOT parse as events
                // — NOT a clean stream close. Throw so run() applies backoff,
                // instead of hot-looping on attempt=0 (the reconnect-storm bug).
                // Mirrors Python, where a 429/5xx surfaces as httpx.HTTPError.
                // Low-speed aborts / disconnects (ok===false) also land here.
                if ($status === 429) {
                    error_log(
                        '[onelo.features] SSE stream: backend at connection cap (HTTP 429'
                        . ($this->currentFallback !== '' ? ', fallback=' . $this->currentFallback : '')
                        . '). Backing off — consider strategy=polling for this deployment.'
                    );
                }
                throw new \RuntimeException(
                    $status !== 0 && $status !== 200
                        ? 'SSE stream returned HTTP ' . $status . ' (transient) — backing off'
                        : 'SSE stream error (curl ' . $errno . '): ' . $err
                );
            default:
                // 'clean' — 200 stream closed by the server. run() resets backoff.
                return;
        }
    }

    /**
     * Classify a finished curl_exec(). Pure + static so the reconnect policy is
     * unit-testable without a socket. A completed transfer (`$ok` true) with a
     * non-200 status is an HTTP error response → 'retry' (backoff), NOT 'clean'.
     *
     * @return 'terminal'|'stop'|'retry'|'clean'
     */
    private static function classifyResult(bool $ok, int $status, bool $stopped): string
    {
        if ($status === 401 || $status === 403) {
            return 'terminal';
        }
        if (!$ok) {
            return $stopped ? 'stop' : 'retry';
        }
        if ($status !== 200 && $status !== 0) {
            return 'retry'; // HTTP error body (429/404/5xx) — transient, back off
        }

        return 'clean';
    }

    /**
     * Extract and dispatch every complete SSE frame currently in the buffer.
     * Frames are separated by a blank line ("\n\n"); a partial trailing frame is
     * left in the buffer for the next write callback.
     */
    private function drainBuffer(): void
    {
        // Normalise CRLF → LF so splitting on "\n\n" is reliable regardless of
        // how the server/proxy terminates lines. A lone trailing "\r" (a chunk
        // that ended mid-CRLF) is left as-is and paired on the next chunk.
        if (str_contains($this->buffer, "\r")) {
            $this->buffer = str_replace("\r\n", "\n", $this->buffer);
        }
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            if ($frame === '') {
                continue;
            }
            $this->handleFrame($frame);
        }
    }

    /** Parse a single SSE frame into (event, data) and dispatch it. */
    private function handleFrame(string $frame): void
    {
        $eventType = '';
        $dataLines = [];
        foreach (explode("\n", $frame) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ':') {
                // Heartbeat / comment line — no field. Receiving these bytes is
                // what keeps curl's low-speed watchdog satisfied; nothing to do.
                continue;
            }
            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, strlen('event:')));
            } elseif (str_starts_with($line, 'data:')) {
                $value = substr($line, strlen('data:'));
                // SSE spec: a single leading space after the colon is stripped.
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
                $dataLines[] = $value;
            }
        }
        $this->handleEvent($eventType, implode("\n", $dataLines));
    }

    /** Dispatch one parsed SSE event. 1:1 with Python `_handle_event`. */
    private function handleEvent(string $eventType, string $data): void
    {
        if ($eventType === '') {
            // Heartbeat / comment / empty frame — ignore (forward-compat).
            return;
        }
        if ($eventType === 'up_to_date') {
            $this->markFirstContact();

            return;
        }
        if ($eventType === 'connected' || $eventType === 'features_updated') {
            $payload = json_decode($data, true);
            if (!is_array($payload) || !isset($payload['features']) || !is_array($payload['features'])) {
                error_log('[onelo.features] malformed ' . $eventType . ' event: missing/invalid features payload');

                return;
            }
            $version = $payload['config_version'] ?? null;
            if (!is_int($version) && !(is_string($version) && is_numeric($version))) {
                error_log('[onelo.features] malformed ' . $eventType . ' event: missing/invalid config_version');

                return;
            }
            // Pass the full per-feature state dicts (status + upsell metadata)
            // straight to the store; it normalises and copies each value.
            $this->store->replaceAll($payload['features'], (int) $version);
            $this->markFirstContact();

            return;
        }
        if ($eventType === 'discovery_requested') {
            // Dashboard's "Discover Features" button broadcasts this. Python's
            // live-key path is read-only for new slugs but fires a batch-ping
            // anyway to bump last_seen_at; test keys get a chance to register
            // freshly-added slugs. The daemon delegates to the injected handler.
            if ($this->onDiscoveryRequested !== null) {
                try {
                    ($this->onDiscoveryRequested)();
                } catch (\Throwable $e) {
                    error_log('[onelo.features] discovery_requested handler failed: ' . $e->getMessage());
                }
            }

            return;
        }
        // Unknown event — forward-compat, ignore.
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

    /**
     * Sleep up to $seconds, waking early if stop() is called. Dispatches pending
     * pcntl signals each step so a SIGTERM during backoff is honoured promptly
     * even when async signals are unavailable.
     */
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
            $step = $remaining < 0.2 ? $remaining : 0.2;
            usleep((int) ($step * 1_000_000));
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
}

/**
 * The stream returned 401/403 — the key is invalid or revoked. Terminal: never
 * retried (mirrors Python's `_SSETerminalAuthError`). Declared alongside the
 * consumer because it is only ever thrown/caught within this file.
 */
final class SseTerminalAuthError extends FeaturesException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("features stream auth failed: HTTP {$status}");
    }
}
