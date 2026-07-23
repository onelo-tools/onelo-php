<?php

declare(strict_types=1);

namespace Onelo\Tests;

use InvalidArgumentException;
use Onelo\Onelo;
use PHPUnit\Framework\TestCase;

final class OneloTest extends TestCase
{
    public function testRequiresAKey(): void
    {
        $this->expectExceptionMessage('requires a key');
        new Onelo(apiUrl: 'https://api.test');
    }

    public function testRejectsMultipleKeys(): void
    {
        $this->expectExceptionMessage('exactly one');
        new Onelo(secretKey: 'onelo_sk_test_a', publishableKey: 'onelo_pk_test_b', apiUrl: 'https://api.test');
    }

    public function testRejectsMalformedKey(): void
    {
        $this->expectExceptionMessage('Key must match');
        new Onelo(key: 'not_a_key', apiUrl: 'https://api.test');
    }

    public function testClassifiesGenericSecretKey(): void
    {
        $c = new Onelo(key: 'onelo_sk_live_x', apiUrl: 'https://api.test');
        self::assertTrue($c->isSecretKey);
    }

    public function testClassifiesGenericPublishableKey(): void
    {
        $c = new Onelo(key: 'onelo_pk_live_x', apiUrl: 'https://api.test');
        self::assertFalse($c->isSecretKey);
    }

    public function testRequiresApiUrl(): void
    {
        $this->expectExceptionMessage('requires apiUrl');
        new Onelo(secretKey: 'onelo_sk_test_a');
    }

    public function testRejectsNonHttpApiUrl(): void
    {
        $this->expectExceptionMessage('http(s) URL');
        new Onelo(secretKey: 'onelo_sk_test_a', apiUrl: 'ftp://x');
    }

    public function testStripsTrailingSlash(): void
    {
        $c = new Onelo(secretKey: 'onelo_sk_test_a', apiUrl: 'https://api.test///');
        self::assertSame('https://api.test', $c->apiUrl);
    }

    public function testDefaultTimeout(): void
    {
        $c = new Onelo(secretKey: 'onelo_sk_test_a', apiUrl: 'https://api.test');
        self::assertSame(5000, $c->requestTimeoutMs);
    }

    public function testRejectsInvalidFeatureEnvironment(): void
    {
        $this->expectExceptionMessage('featureEnvironment must be');
        new Onelo(secretKey: 'onelo_sk_test_a', apiUrl: 'https://api.test', featureEnvironment: 'bogus');
    }

    public function testToleratesUnknownConfigKeys(): void
    {
        // Forward-compat: an unrecognised named arg must NOT fatally reject —
        // it warns and is ignored (parity with Python's Onelo(**deprecated)).
        $c = new Onelo(
            secretKey: 'onelo_sk_test_a',
            apiUrl: 'https://api.test',
            discoveryKey: 'removed-in-a-future-version',
        );
        self::assertSame('onelo_sk_test_a', $c->key);
    }
}
