<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * Shared snapshot normalisation — mirrors Python ThreadSafeCache::_normalize.
 * A per-feature value may arrive as a full wire dict (kept) or a bare status
 * string (legacy/degenerate → ['status' => value]). Missing status is left to
 * the reader, which fails closed to 'hidden'.
 */
trait NormalizesSnapshot
{
    /**
     * @param array<string, mixed> $features
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function normalizeAll(array $features): array
    {
        $out = [];
        foreach ($features as $name => $value) {
            $out[(string) $name] = self::normalizeOne($value);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected static function normalizeOne(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return ['status' => is_string($value) ? $value : (string) $value];
    }

    /** @param array<string, mixed> $state */
    protected static function statusOf(array $state): string
    {
        $status = $state['status'] ?? 'hidden';

        return is_string($status) ? $status : 'hidden';
    }
}
