<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * Resolves the stream strategy. 1:1 with Python's `_detection.resolve_strategy`:
 * "auto" maps to "sse" (SSE-per-listener is the default), "redis" is reserved for
 * a future coordinator and rejected explicitly, anything else is an error.
 */
final class StrategyResolver
{
    private const VALID = ['auto', 'sse', 'polling'];
    private const DEFERRED = ['redis'];

    /** @return 'sse'|'polling' */
    public static function resolve(string $strategy): string
    {
        if (in_array($strategy, self::DEFERRED, true)) {
            throw new \InvalidArgumentException(
                "strategy='{$strategy}' is not supported yet. Use 'sse' or 'polling'. "
                . 'A Redis pub/sub coordinator may land later.'
            );
        }
        if (!in_array($strategy, self::VALID, true)) {
            throw new \InvalidArgumentException(
                "strategy must be one of 'auto', 'sse', 'polling', got '{$strategy}'"
            );
        }

        return $strategy === 'auto' ? 'sse' : $strategy;
    }
}
