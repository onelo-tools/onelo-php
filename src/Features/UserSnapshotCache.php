<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * Tiny TTL cache of per-user feature snapshots keyed by user_id — stops a busy
 * multi-user backend from calling /resolve on every request for the same user.
 * 1:1 with Python's `_UserSnapshotCache`. Bounded two ways: lazy prune (an
 * expired entry is deleted on the get() that finds it) + a hard cap (put() purges
 * expired then evicts the entries closest to expiry). Monotonic clock (hrtime).
 */
final class UserSnapshotCache
{
    private const MAX_ENTRIES = 10_000;

    /** @var array<string, array{0: float, 1: array<string, mixed>}> user_id → [deadline_s, snapshot] */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = self::MAX_ENTRIES)
    {
    }

    /** @return array<string, mixed>|null the snapshot, or null on miss/expiry */
    public function get(string $userId): ?array
    {
        $entry = $this->entries[$userId] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry[0] > self::now()) {
            return $entry[1];
        }
        unset($this->entries[$userId]); // expired — lazy prune

        return null;
    }

    /** @param array<string, mixed> $snapshot */
    public function put(string $userId, array $snapshot, float $ttl): void
    {
        $this->entries[$userId] = [self::now() + $ttl, $snapshot];
        if (count($this->entries) > $this->maxEntries) {
            $this->evict();
        }
    }

    public function invalidate(string $userId): void
    {
        unset($this->entries[$userId]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /** Drop expired entries first (free); if still over cap, evict closest-to-expiry. */
    private function evict(): void
    {
        $now = self::now();
        foreach ($this->entries as $k => [$deadline]) {
            if ($deadline <= $now) {
                unset($this->entries[$k]);
            }
        }
        $overflow = count($this->entries) - $this->maxEntries;
        if ($overflow > 0) {
            // Sort by deadline ascending, evict the soonest-to-lapse.
            uasort($this->entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $i = 0;
            foreach (array_keys($this->entries) as $k) {
                if ($i++ >= $overflow) {
                    break;
                }
                unset($this->entries[$k]);
            }
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
