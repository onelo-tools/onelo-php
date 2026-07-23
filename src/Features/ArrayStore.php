<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * In-process snapshot store — the direct analogue of Python's ThreadSafeCache.
 * Correct when the reader and the stream listener share one process: a CLI
 * daemon, Laravel Octane / Swoole (in-process shared state), or tests. Under
 * plain PHP-FPM use {@see ApcuStore} instead (an ArrayStore written by the
 * listener process is invisible to the web workers).
 */
final class ArrayStore implements SnapshotStore
{
    use NormalizesSnapshot;

    /** @var array<string, array<string, mixed>> */
    private array $features = [];
    private int $configVersion = 0;

    public function getState(string $name): array
    {
        $state = $this->features[$name] ?? null;

        return $state !== null ? $state : ['status' => 'hidden'];
    }

    public function getStatus(string $name): string
    {
        $state = $this->features[$name] ?? null;

        return $state !== null ? self::statusOf($state) : 'hidden';
    }

    public function replaceAll(array $features, int $version): void
    {
        $this->features = self::normalizeAll($features);
        $this->configVersion = $version;
    }

    public function configVersion(): int
    {
        return $this->configVersion;
    }

    public function snapshot(): array
    {
        $out = [];
        foreach ($this->features as $name => $state) {
            $out[$name] = self::statusOf($state);
        }

        return $out;
    }
}
