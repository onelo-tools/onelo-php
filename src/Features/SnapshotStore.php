<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * The feature-snapshot store — PHP's stand-in for Python's in-process
 * `ThreadSafeCache`. Because PHP-FPM has NO shared memory across requests, the
 * stream listener (a long-lived process) writes the snapshot here and the web
 * request workers read it. Implementations:
 *   - {@see ArrayStore}: in-process — only when the listener and the reader are
 *     the SAME process (Laravel Octane / Swoole, a CLI app, tests).
 *   - {@see FileStore}: filesystem — the zero-infra bridge when the listener is a
 *     SEPARATE process from the PHP-FPM web workers (the common deployment).
 *   - A Redis store is the drop-in for multi-server fleets.
 *
 * NOTE: APCu is deliberately NOT a listener bridge — its shared segment spans one
 * SAPI (the FPM pool) only, so a separate CLI daemon can't write into the FPM
 * workers' APCu. It is useful only as a within-FPM cache for the /resolve fallback.
 *
 * Stores the FULL per-feature wire state dict (status + upsell metadata), keyed
 * by feature name, plus the monotonic config_version. Missing feature →
 * ['status' => 'hidden'] (fail-closed, per the SDK protocol spec).
 */
interface SnapshotStore
{
    /**
     * Full wire state dict for `name`, or ['status' => 'hidden'] on miss.
     *
     * @return array<string, mixed>
     */
    public function getState(string $name): array;

    /** Wire status string for `name`, or "hidden" on miss (status-only callers). */
    public function getStatus(string $name): string;

    /**
     * Atomically replace the entire snapshot. Each value may be a full wire dict
     * or a bare status string (normalised to ['status' => value]).
     *
     * @param array<string, mixed> $features
     */
    public function replaceAll(array $features, int $version): void;

    /** The current snapshot's config version (0 before the first snapshot lands). */
    public function configVersion(): int;

    /**
     * Stable copy of the current name→status mapping (status-only). Used by the
     * monitor module to attach the active feature-flag state to captured events.
     *
     * @return array<string, string>
     */
    public function snapshot(): array;
}
