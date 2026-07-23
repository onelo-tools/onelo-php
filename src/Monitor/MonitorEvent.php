<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * The wire envelope POSTed to /api/sdk/monitor/events/batch. Mutable on purpose —
 * the capture pipeline enriches `meta` (scope, flags, sdk, deploy context, scrub)
 * before transport. 1:1 with Python's MonitorEvent.
 */
final class MonitorEvent
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public string $featureName,
        public bool $ok,
        /** Epoch SECONDS at event creation (serialised to ISO `ts` on the wire). */
        public float $timestamp,
        public ?int $durationMs = null,
        public ?string $error = null,
        public string $source = 'event',
        public ?string $userId = null,
        public string $platform = 'php',
        public ?string $sessionId = null,
        public array $meta = [],
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function make(string $featureName, bool $ok, ?string $error = null, string $source = 'event', ?int $durationMs = null, array $meta = []): self
    {
        return new self(
            featureName: $featureName,
            ok: $ok,
            timestamp: microtime(true),
            durationMs: $durationMs,
            error: $error,
            source: $source,
            meta: $meta,
        );
    }

    /** @return array<string, mixed> the JSON shape the backend expects */
    public function toWire(): array
    {
        $out = [
            'featureName' => $this->featureName,
            'ok' => $this->ok,
            'platform' => $this->platform,
            'source' => $this->source,
            // Real event time (captured at creation), so a batched/shutdown-flushed
            // event isn't mis-dated to ingest time. ISO-8601 UTC with milliseconds.
            'ts' => self::isoTs($this->timestamp),
        ];
        if ($this->durationMs !== null) {
            $out['durationMs'] = $this->durationMs;
        }
        if ($this->error !== null) {
            $out['error'] = $this->error;
        }
        if ($this->userId !== null) {
            $out['userId'] = $this->userId;
        }
        if ($this->sessionId !== null) {
            $out['sessionId'] = $this->sessionId;
        }
        if ($this->meta !== []) {
            $out['meta'] = $this->meta;
        }

        return $out;
    }

    private static function isoTs(float $epochSeconds): string
    {
        $ms = (int) round(($epochSeconds - floor($epochSeconds)) * 1000);
        if ($ms === 1000) {
            $ms = 999;
        }

        return gmdate('Y-m-d\TH:i:s', (int) $epochSeconds) . sprintf('.%03dZ', $ms);
    }
}
