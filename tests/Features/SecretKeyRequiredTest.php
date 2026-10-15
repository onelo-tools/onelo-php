<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\FeaturesClient;
use Onelo\Features\FeaturesException;
use Onelo\Features\Listener;
use Onelo\Features\PollingConsumer;
use Onelo\Features\SecretKeyRequired;
use Onelo\Features\SseConsumer;
use Onelo\Onelo;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * #520: per-user Features calls from a server SDK require the SECRET key. A
 * publishable key is public, so a pk-signed call naming a userId is exactly as
 * spoofable as a client — the SDK refuses it before sending anything.
 * Anonymous/global calls with a publishable key keep working.
 */
final class SecretKeyRequiredTest extends TestCase
{
    private const FIX = '/use your secret key \(onelo_sk_/';

    public function testForUserWithPublishableKeyThrowsAndSendsNothing(): void
    {
        $fake = FakeTransport::json(200, ['features' => []]);
        $f = new FeaturesClient(Fixtures::publishableClient($fake), new ArrayStore(), false);
        try {
            $f->forUser('u1');
            self::fail('expected SecretKeyRequired');
        } catch (SecretKeyRequired $e) {
            self::assertMatchesRegularExpression(self::FIX, $e->getMessage());
            self::assertInstanceOf(FeaturesException::class, $e);
        }
        self::assertSame(0, $fake->calls);
    }

    public function testForUserWithPkViaGenericKeyThrows(): void
    {
        $fake = FakeTransport::json(200, ['features' => []]);
        $client = new Onelo(key: 'onelo_pk_live_x', apiUrl: 'https://api.test', transport: $fake);
        $this->expectException(SecretKeyRequired::class);
        $client->features->forUser('u1');
    }

    public function testPublishableKeyWithSkInItsTailIsStillPublishable(): void
    {
        $this->expectException(SecretKeyRequired::class);
        SecretKeyRequired::assertFor('onelo_pk_live_sk_abc', 'forUser');
    }

    public function testIdentifyUserWithPublishableKeyThrows(): void
    {
        $f = new FeaturesClient(Fixtures::publishableClient(FakeTransport::json(200, [])), new ArrayStore(), false);
        $this->expectException(SecretKeyRequired::class);
        $this->expectExceptionMessageMatches(self::FIX);
        $f->identify('u1');
    }

    public function testAnonymousUseKeepsWorkingWithPublishableKey(): void
    {
        $fake = FakeTransport::json(200, []);
        $f = new FeaturesClient(Fixtures::publishableClient($fake), new ArrayStore(), false);
        $f->identify(null);
        $f->identify('');
        self::assertFalse($f->isEnabled('anything'));
        new Listener(Fixtures::publishableClient($fake), new ArrayStore(), 'polling');
        new PollingConsumer(store: new ArrayStore(), key: 'onelo_pk_test_abc', apiUrl: 'https://api.test');
        new SseConsumer(store: new ArrayStore(), key: 'onelo_pk_test_abc', apiUrl: 'https://api.test');
    }

    public function testPollingConsumerWithUserAndPublishableKeyThrows(): void
    {
        $this->expectException(SecretKeyRequired::class);
        new PollingConsumer(store: new ArrayStore(), key: 'onelo_pk_test_abc', apiUrl: 'https://api.test', userId: 'u1');
    }

    public function testSseConsumerWithUserAndPublishableKeyThrows(): void
    {
        $this->expectException(SecretKeyRequired::class);
        new SseConsumer(store: new ArrayStore(), key: 'onelo_pk_test_abc', apiUrl: 'https://api.test', userId: 'u1');
    }

    public function testListenerWithUserAndPublishableKeyThrows(): void
    {
        $this->expectException(SecretKeyRequired::class);
        new Listener(Fixtures::publishableClient(FakeTransport::json(200, [])), new ArrayStore(), 'sse', userId: 'u1');
    }

    public function testSecretKeyPerUserCallsAreUnchanged(): void
    {
        $fake = FakeTransport::json(200, ['features' => ['f' => ['status' => 'enabled']]]);
        $f = new FeaturesClient(Fixtures::secretClient($fake), new ArrayStore(), false);
        self::assertTrue($f->forUser('u1')->isEnabled('f'));
        $body = json_decode((string) $fake->lastBody, true);
        self::assertSame('onelo_sk_test_abc123', $body['publishableKey']);
        self::assertSame('u1', $body['userId']);
        $f->identify('u1');
        new PollingConsumer(store: new ArrayStore(), key: 'onelo_sk_test_abc', apiUrl: 'https://api.test', userId: 'u1');
        new SseConsumer(store: new ArrayStore(), key: 'onelo_sk_test_abc', apiUrl: 'https://api.test', userId: 'u1');
    }
}
