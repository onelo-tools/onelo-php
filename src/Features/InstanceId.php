<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * Per-install instance UUID for the TOFU device binding (X-Onelo-Instance-Id on
 * batch-ping / stream). 1:1 with Python's get_instance_id: env ONELO_INSTANCE_ID
 * wins (for ephemeral containers), else a UUID persisted under ONELO_INSTANCE_DIR
 * (default ~/.onelo), generated once on first use.
 */
final class InstanceId
{
    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $env = getenv('ONELO_INSTANCE_ID');
        if (is_string($env) && $env !== '') {
            return self::$cached = $env;
        }

        $dir = getenv('ONELO_INSTANCE_DIR') ?: (self::home() . '/.onelo');
        $file = $dir . '/onelo-instance-id';
        if (is_file($file)) {
            $existing = trim((string) @file_get_contents($file));
            if ($existing !== '') {
                return self::$cached = $existing;
            }
        }
        $id = self::uuid4();
        if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
            @file_put_contents($file, $id);
        }

        return self::$cached = $id;
    }

    private static function home(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        return sys_get_temp_dir();
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
