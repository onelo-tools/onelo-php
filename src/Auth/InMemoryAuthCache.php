<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Default in-process LRU-with-TTL cache. Bounded by $maxSize (oldest evicted when
 * full); each entry has its own absolute expiry on the monotonic clock. PHP
 * associative arrays preserve insertion order, so a hit is re-inserted to become
 * most-recently-used (O(1)) and the oldest is `array_shift`-ed on overflow. (The
 * shift is O(n), but it only runs once the cap is reached — which, being
 * request-scoped under PHP-FPM, effectively never happens in one request.)
 *
 * Request-scoped under PHP-FPM — see {@see AuthCache} for cross-request caching.
 */
final class InMemoryAuthCache implements AuthCache
{
    /** @var array<string, array{user: OneloUser, expiry: float}> */
    private array $store = [];

    public function __construct(private readonly int $maxSize = 10_000)
    {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('maxSize must be >= 1');
        }
    }

    public function get(string $tokenHash): ?OneloUser
    {
        $entry = $this->store[$tokenHash] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($this->nowMs() >= $entry['expiry']) {
            unset($this->store[$tokenHash]);

            return null;
        }
        // Move to MRU end (unset + re-append).
        unset($this->store[$tokenHash]);
        $this->store[$tokenHash] = $entry;

        return $entry['user'];
    }

    public function set(string $tokenHash, OneloUser $user, float $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return; // do not cache
        }
        unset($this->store[$tokenHash]);
        $this->store[$tokenHash] = ['user' => $user, 'expiry' => $this->nowMs() + $ttlSeconds * 1000];
        while (count($this->store) > $this->maxSize) {
            array_shift($this->store); // evict oldest (first inserted)
        }
    }

    public function invalidate(string $tokenHash): void
    {
        unset($this->store[$tokenHash]);
    }

    private function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
