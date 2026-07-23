<?php

declare(strict_types=1);

namespace Onelo\Tests\Support;

use Onelo\Http\Transport;
use Onelo\Onelo;

/** Shared helpers for building clients in tests. */
final class Fixtures
{
    /** @var array<string, mixed> */
    public const OK_USER = ['id' => 'user_1', 'email' => 'a@b.co', 'metadata' => ['plan' => 'pro']];

    public static function secretClient(Transport $transport): Onelo
    {
        return new Onelo(
            secretKey: 'onelo_sk_test_abc123',
            apiUrl: 'https://api.test.onelo.tools',
            transport: $transport,
        );
    }

    public static function publishableClient(Transport $transport): Onelo
    {
        return new Onelo(
            publishableKey: 'onelo_pk_test_abc123',
            apiUrl: 'https://api.test.onelo.tools',
            transport: $transport,
        );
    }
}
