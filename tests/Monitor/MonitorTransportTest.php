<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Http\HttpResponse;
use Onelo\Http\Transport;
use Onelo\Monitor\MonitorEvent;
use Onelo\Monitor\MonitorTransport;
use Onelo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MonitorTransportTest extends TestCase
{
    private function transport(FakeTransport $fake, int $bufferSize = 200): MonitorTransport
    {
        return new MonitorTransport(
            apiUrl: 'https://api.test.onelo.tools',
            key: 'onelo_pk_test_abc',
            transport: $fake,
            requestTimeoutMs: 1000,
            bufferSize: $bufferSize,
        );
    }

    private function event(string $name = 'f'): MonitorEvent
    {
        return MonitorEvent::make($name, true);
    }

    public function testFlushEmptyBufferDoesNotSend(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true]);
        $mt = $this->transport($fake);
        $mt->flush();
        self::assertSame(0, $fake->calls);
    }

    public function testAddThenFlushSendsBatchAndClears(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true]);
        $mt = $this->transport($fake);
        $mt->add($this->event('a'));
        $mt->add($this->event('b'));
        self::assertSame(2, $mt->bufferLength());
        $mt->flush();
        self::assertSame(1, $fake->calls);
        self::assertSame(0, $mt->bufferLength());
        $payload = json_decode((string) $fake->lastBody, true);
        self::assertSame('onelo_pk_test_abc', $payload['publishableKey']);
        self::assertCount(2, $payload['events']);
        self::assertSame('a', $payload['events'][0]['featureName']);
    }

    public function testBufferOverflowDropsOldest(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true]);
        $mt = $this->transport($fake, bufferSize: 2);
        $mt->add($this->event('a'));
        $mt->add($this->event('b'));
        $mt->add($this->event('c')); // evicts 'a'
        self::assertSame(2, $mt->bufferLength());
        $mt->flush();
        $payload = json_decode((string) $fake->lastBody, true);
        self::assertSame(['b', 'c'], array_column($payload['events'], 'featureName'));
    }

    public function testSuccessNotesQuotaRemaining(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true], ['X-Onelo-Quota-Remaining' => '17']);
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(17, $mt->quotaRemaining());
    }

    public function testUnlimitedQuotaClearsRemaining(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true], ['X-Onelo-Quota-Remaining' => 'unlimited']);
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertNull($mt->quotaRemaining());
    }

    public function testQuotaExhaustedTriggersHoldoff(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true], ['X-Onelo-Quota-Remaining' => '0']);
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(0, $mt->quotaRemaining());
        // Next non-forced flush is suppressed by the hold-off window.
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(1, $fake->calls); // still only the first send
        // Forced flush bypasses the hold-off.
        $mt->flush(true);
        self::assertSame(2, $fake->calls);
    }

    public function testRateLimitHoldoffThenForce(): void
    {
        $fake = new FakeTransport([
            ['status' => 429, 'body' => '{}', 'headers' => ['Retry-After' => '30']],
            ['status' => 200, 'body' => '{"ok":true}', 'headers' => []],
        ]);
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(1, $fake->calls); // 429 is not retried inside the batch
        self::assertSame(0, $mt->bufferLength()); // batch consumed, not requeued
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(1, $fake->calls); // suppressed by Retry-After hold-off
        $mt->flush(true);
        self::assertSame(2, $fake->calls);
    }

    public function testServerErrorRetriesThenSucceeds(): void
    {
        $fake = new FakeTransport([
            ['status' => 500, 'body' => 'err', 'headers' => []],
            ['status' => 200, 'body' => '{"ok":true}', 'headers' => []],
        ]);
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(2, $fake->calls); // retried once after 5xx
    }

    public function testClientErrorNotRetried(): void
    {
        $fake = FakeTransport::raw(422, 'bad');
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(1, $fake->calls);
    }

    public function testNetworkFailureDropsBatchAfterRetries(): void
    {
        $fake = FakeTransport::throwing();
        $mt = $this->transport($fake);
        $mt->add($this->event());
        $mt->flush();
        self::assertSame(3, $fake->calls); // MAX_RETRY attempts, then dropped
        self::assertSame(0, $mt->bufferLength());
    }

    public function testNonTransportThrowableIsCaughtBatchDroppedNotRetried(): void
    {
        // A custom transport that throws something OTHER than TransportException
        // (e.g. a Guzzle/Symfony wrapper raising \RuntimeException) must not
        // escape flush() and must not be retried.
        $throwing = new class implements Transport {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse
            {
                $this->calls++;

                throw new \RuntimeException('guzzle blew up');
            }
        };
        $mt = new MonitorTransport(
            apiUrl: 'https://api.test.onelo.tools',
            key: 'onelo_pk_test_abc',
            transport: $throwing,
            requestTimeoutMs: 1000,
        );
        $mt->add($this->event());
        $mt->flush(); // must NOT throw
        self::assertSame(1, $throwing->calls); // dropped immediately, not retried
        self::assertSame(0, $mt->bufferLength());
    }
}
