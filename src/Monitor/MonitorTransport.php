<?php

declare(strict_types=1);

namespace Onelo\Monitor;

use Onelo\Http\Transport;
use Onelo\Http\TransportException;
use Onelo\Version;

/**
 * Buffers monitor events and POSTs them to /api/sdk/monitor/events/batch. PHP has
 * no background thread, so delivery is SYNCHRONOUS on {@see flush()} — which the
 * MonitorClient registers via `register_shutdown_function` (drain at request end).
 *
 * Unlike the Node transport (which flushes eagerly on every error event via an
 * async, non-blocking send), PHP does NOT flush per-error: here `flush()` is a
 * blocking network POST, so draining inside a live request would add latency to
 * every caught error. Under PHP-FPM the shutdown drain fires at request end and
 * delivers everything; long-running contexts (CLI/queue/Octane) call flush()
 * explicitly per job. Retry + 429/quota self-throttle semantics are 1:1 with
 * the Node/Python transport.
 */
final class MonitorTransport
{
    private const MAX_RETRY = 3;
    private const QUOTA_HOLDOFF_MS = 300_000;
    private const QUOTA_LOW = 50;
    private const MAX_RETRY_AFTER_MS = 3_600_000;

    /** @var list<MonitorEvent> */
    private array $buffer = [];
    private int $dropped = 0;
    private int $droppedReported = 0;
    private float $retryAfterUntilMs = 0.0;
    private ?int $quotaRemaining = null;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $key,
        private readonly Transport $transport,
        private readonly int $requestTimeoutMs = 5000,
        private readonly int $bufferSize = 200,
    ) {
    }

    public function add(MonitorEvent $event): void
    {
        if (count($this->buffer) >= $this->bufferSize) {
            array_shift($this->buffer);
            $this->dropped++;
        }
        $this->buffer[] = $event;
    }

    public function bufferLength(): int
    {
        return count($this->buffer);
    }

    public function quotaRemaining(): ?int
    {
        return $this->quotaRemaining;
    }

    /** Drain the buffer synchronously. Honours any active 429/quota hold-off. */
    public function flush(bool $force = false): void
    {
        if (!$force && $this->retryAfterUntilMs > 0 && self::nowMs() < $this->retryAfterUntilMs) {
            return;
        }
        if ($this->buffer === []) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        $this->sendBatchWithRetry($batch);
        $this->reportDrops();
    }

    /** @param list<MonitorEvent> $batch */
    private function sendBatchWithRetry(array $batch): void
    {
        $backoff = 500;
        for ($attempt = 1; $attempt <= self::MAX_RETRY; $attempt++) {
            if ($this->sendOnce($batch)) {
                return;
            }
            if ($attempt < self::MAX_RETRY) {
                usleep($backoff * 1000);
                $backoff *= 2;
            }
        }
        error_log('[onelo.monitor] dropping batch of ' . count($batch) . ' events after ' . self::MAX_RETRY . ' attempts');
    }

    /**
     * @param list<MonitorEvent> $batch
     *
     * @return bool true when the batch is done (success OR non-retryable failure)
     */
    private function sendOnce(array $batch): bool
    {
        $payload = json_encode([
            'publishableKey' => $this->key,
            'events' => array_map(static fn (MonitorEvent $e): array => $e->toWire(), $batch),
        ]);
        try {
            $resp = $this->transport->send(
                'POST',
                $this->apiUrl . '/api/sdk/monitor/events/batch',
                ['Content-Type' => 'application/json', 'User-Agent' => 'onelo-php/' . Version::VERSION],
                $payload === false ? '{}' : $payload,
                $this->requestTimeoutMs,
            );
        } catch (TransportException) {
            return false; // network — retry
        } catch (\Throwable $e) {
            // A custom Transport misbehaved (threw something other than the
            // documented TransportException). Never let it escape flush() — that
            // would break a CLI/queue job or the shutdown drain — and never
            // silently lose the batch. Log + drop (retrying a non-network fault
            // won't help).
            error_log('[onelo.monitor] transport threw ' . get_class($e) . ': ' . $e->getMessage() . '; dropping batch');

            return true;
        }
        $status = $resp->status;
        if ($status >= 200 && $status < 300) {
            $this->noteQuota($resp->header('X-Onelo-Quota-Remaining'));

            return true;
        }
        if ($status === 429) {
            $retryAfterMs = self::parseRetryAfter($resp->header('Retry-After'));
            if ($retryAfterMs > 0) {
                $this->retryAfterUntilMs = self::nowMs() + $retryAfterMs;
            }

            return true; // don't retry — quota won't move
        }
        if ($status >= 400 && $status < 500) {
            return true; // 401/403/422 — pointless to retry
        }

        return false; // 5xx — retry
    }

    private function noteQuota(?string $header): void
    {
        if ($header === null) {
            return;
        }
        $h = trim($header);
        if ($h === '') {
            return;
        }
        if (strtolower($h) === 'unlimited') {
            $this->quotaRemaining = null;

            return;
        }
        if (!is_numeric($h)) {
            return;
        }
        $remaining = (int) $h;
        $this->quotaRemaining = $remaining;
        if ($remaining <= 0) {
            $this->retryAfterUntilMs = max($this->retryAfterUntilMs, self::nowMs() + self::QUOTA_HOLDOFF_MS);
            error_log('[onelo.monitor] event quota exhausted; holding off ~' . (self::QUOTA_HOLDOFF_MS / 1000) . 's before retry');
        } elseif ($remaining <= self::QUOTA_LOW) {
            error_log('[onelo.monitor] event quota low — ' . $remaining . ' remaining this hour');
        }
    }

    private function reportDrops(): void
    {
        $newly = $this->dropped - $this->droppedReported;
        $this->droppedReported = $this->dropped;
        if ($newly > 0) {
            error_log('[onelo.monitor] dropped ' . $newly . ' event(s) — buffer full (max ' . $this->bufferSize . '). Raise bufferSize or lower volume via sampleRate.');
        }
    }

    private static function parseRetryAfter(?string $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        $v = trim($value);
        if (!is_numeric($v)) {
            return 0.0;
        }
        $seconds = (float) $v;

        return max(0.0, min($seconds * 1000, self::MAX_RETRY_AFTER_MS));
    }

    private static function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
