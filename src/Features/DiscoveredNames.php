<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * The set of feature slugs this process has ever asked about — drives the
 * debounced auto-discovery batch-ping. 1:1 with Python's `_DiscoveredNames` (no
 * lock needed: one PHP-FPM request = one process, no concurrent threads).
 */
final class DiscoveredNames
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @return bool true if `name` was newly added (not seen before). */
    public function add(string $name): bool
    {
        if (isset($this->seen[$name])) {
            return false;
        }
        $this->seen[$name] = true;

        return true;
    }

    /**
     * @param array<int, string> $names
     *
     * @return bool true if ANY name was newly added.
     */
    public function addMany(array $names): bool
    {
        $new = false;
        foreach ($names as $name) {
            if ($this->add($name)) {
                $new = true;
            }
        }

        return $new;
    }

    /** @return array<int, string> */
    public function snapshot(): array
    {
        return array_keys($this->seen);
    }
}
