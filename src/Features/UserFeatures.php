<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * Resolved feature snapshot for ONE specific user — returned by
 * FeaturesClient::forUser(). Read-only. 1:1 with Python's `UserFeatures`.
 *
 * Unlike the process-global feature() path (which reads a single shared
 * snapshot), this carries a per-user snapshot and never mutates SDK identity —
 * so it is SAFE to use per request in a multi-user backend (HTTP handlers), with
 * no cross-user race.
 */
final class UserFeatures
{
    /** @param array<string, mixed> $states wire state dicts keyed by feature name */
    public function __construct(
        public readonly string $userId,
        private readonly array $states = [],
    ) {
    }

    /**
     * This feature's state (incl. upsell metadata) FOR THIS USER. Missing from
     * the snapshot → hidden (fail-closed), mirroring the global feature().
     */
    public function feature(string $name): Feature
    {
        return Feature::fromWire($name, $this->states[$name] ?? ['status' => 'hidden']);
    }

    /** Shorthand for feature(name)->isEnabled(). */
    public function isEnabled(string $name): bool
    {
        return $this->feature($name)->isEnabled();
    }
}
