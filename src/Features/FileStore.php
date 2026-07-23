<?php

declare(strict_types=1);

namespace Onelo\Features;

use Onelo\Features\FeaturesException;

/**
 * Filesystem snapshot store — the zero-infra bridge between a SEPARATE stream
 * listener process (a supervised CLI daemon) and the PHP-FPM web workers. The
 * listener {@see replaceAll()}s the snapshot to a JSON file; every request reads
 * it. Cross-process and cross-SAPI (unlike APCu), needs no Redis.
 *
 * Writes are atomic: serialise to a temp file in the same dir, then rename() over
 * the target (POSIX rename is atomic within a filesystem), so a reader never sees
 * a half-written snapshot. Reads tolerate a missing/not-yet-written file
 * (config_version 0, everything hidden — fail-closed) so the app boots fine
 * before the listener's first snapshot lands.
 */
final class FileStore implements SnapshotStore
{
    use NormalizesSnapshot;

    public function __construct(private readonly string $path)
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new FeaturesException("Onelo FileStore: cannot create directory {$dir}");
        }
    }

    public function getState(string $name): array
    {
        $features = $this->read()['features'];
        $state = $features[$name] ?? null;

        return is_array($state) ? $state : ['status' => 'hidden'];
    }

    public function getStatus(string $name): string
    {
        $features = $this->read()['features'];
        $state = $features[$name] ?? null;

        return is_array($state) ? self::statusOf($state) : 'hidden';
    }

    public function replaceAll(array $features, int $version): void
    {
        $payload = json_encode(
            ['config_version' => $version, 'features' => self::normalizeAll($features)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($payload === false) {
            throw new FeaturesException('Onelo FileStore: failed to encode snapshot');
        }
        // Atomic replace: write to a unique temp file in the SAME directory, then
        // rename over the target. getmypid()+uniqid avoids collisions between
        // concurrent writers (there should only be one listener, but be safe).
        $tmp = $this->path . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new FeaturesException("Onelo FileStore: failed to write {$tmp}");
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new FeaturesException("Onelo FileStore: failed to rename into {$this->path}");
        }
    }

    public function configVersion(): int
    {
        return $this->read()['config_version'];
    }

    public function snapshot(): array
    {
        $out = [];
        foreach ($this->read()['features'] as $name => $state) {
            if (is_array($state)) {
                $out[$name] = self::statusOf($state);
            }
        }

        return $out;
    }

    /**
     * Read + decode the snapshot file. Missing/corrupt → empty, version 0
     * (fail-closed). Never throws on read: gating must survive a transient FS
     * hiccup by falling back to "everything hidden".
     *
     * @return array{config_version: int, features: array<string, mixed>}
     */
    private function read(): array
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return ['config_version' => 0, 'features' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
            return ['config_version' => 0, 'features' => []];
        }
        $version = $data['config_version'] ?? 0;

        return [
            'config_version' => is_int($version) ? $version : (int) $version,
            'features' => $data['features'],
        ];
    }
}
