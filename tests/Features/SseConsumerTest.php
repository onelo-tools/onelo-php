<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\SseConsumer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the SSE frame parser + event dispatch directly (no network): set the
 * private buffer and invoke drainBuffer() via reflection, then assert on the
 * store. The curl I/O around it is thin; the parser is where bugs hide.
 */
final class SseConsumerTest extends TestCase
{
    private function feed(SseConsumer $c, string $bytes): void
    {
        $ref = new \ReflectionClass($c);
        $buf = $ref->getProperty('buffer');
        $buf->setValue($c, $bytes);
        $ref->getMethod('drainBuffer')->invoke($c);
    }

    private function consumer(ArrayStore $store, ?callable $onDiscovery = null): SseConsumer
    {
        return new SseConsumer(
            store: $store,
            key: 'onelo_pk_test_abc',
            apiUrl: 'https://api.test.onelo.tools',
            onDiscoveryRequested: $onDiscovery,
        );
    }

    /**
     * ADR-0003: the Event Stream's credential is a request header for every SDK
     * but browser JS. A key in the query string lands in the reverse proxy's
     * access.log on every connect, so it must not appear in the URL at all.
     */
    public function testKeyTravelsAsHeaderAndNeverInTheStreamUrl(): void
    {
        $c = $this->consumer(new ArrayStore());
        $ref = new \ReflectionClass($c);
        $url = $ref->getMethod('streamUrl')->invoke($c);
        $headers = $ref->getMethod('streamHeaders')->invoke($c);

        self::assertContains('X-Publishable-Key: onelo_pk_test_abc', $headers);
        self::assertStringNotContainsString('key=', $url);
        self::assertStringNotContainsString('onelo_pk_test_abc', $url);
        // The transport headers the stream already sent are untouched.
        self::assertContains('Accept: text/event-stream', $headers);
        self::assertContains('Cache-Control: no-cache', $headers);
    }

    public function testStreamUrlStillCarriesTheNonSecretIdentity(): void
    {
        $c = $this->consumer(new ArrayStore());
        $url = (new \ReflectionClass($c))->getMethod('streamUrl')->invoke($c);

        self::assertStringStartsWith('https://api.test.onelo.tools/api/sdk/features/stream?', $url);
        self::assertStringContainsString('since_version=0', $url);
        self::assertStringContainsString('sdk_platform=php', $url);
    }

    public function testFeaturesUpdatedPopulatesStore(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: features_updated\ndata: {\"features\":{\"x\":{\"status\":\"enabled\"}},\"config_version\":3}\n\n");
        self::assertSame('enabled', $store->getStatus('x'));
        self::assertSame(3, $store->configVersion());
        self::assertTrue($c->firstContact());
    }

    public function testConnectedEventAlsoPopulates(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: connected\ndata: {\"features\":{\"y\":{\"status\":\"upsell\",\"required_plan_label\":\"Pro\"}},\"config_version\":1}\n\n");
        self::assertSame('upsell', $store->getStatus('y'));
        self::assertSame('Pro', $store->getState('y')['required_plan_label']);
    }

    public function testUpToDateMarksFirstContactWithoutTouchingStore(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: up_to_date\ndata: {}\n\n");
        self::assertTrue($c->firstContact());
        self::assertSame(0, $store->configVersion());
    }

    public function testDiscoveryRequestedFiresCallback(): void
    {
        $fired = false;
        $c = $this->consumer(new ArrayStore(), function () use (&$fired): void { $fired = true; });
        $this->feed($c, "event: discovery_requested\ndata: {}\n\n");
        self::assertTrue($fired);
    }

    public function testHeartbeatAndUnknownEventsIgnored(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, ": keepalive\n\nevent: some_future_event\ndata: {\"a\":1}\n\n");
        self::assertSame(0, $store->configVersion()); // nothing applied
        self::assertFalse($c->firstContact());
    }

    public function testMalformedFeaturesEventLeavesStoreUntouched(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: features_updated\ndata: not-json\n\n");
        self::assertSame(0, $store->configVersion());
        // Missing config_version is also malformed:
        $this->feed($c, "event: features_updated\ndata: {\"features\":{\"x\":{\"status\":\"enabled\"}}}\n\n");
        self::assertSame(0, $store->configVersion());
    }

    public function testMultipleFramesInOneChunk(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed(
            $c,
            "event: up_to_date\ndata: {}\n\n"
            . "event: features_updated\ndata: {\"features\":{\"a\":{\"status\":\"enabled\"},\"b\":{\"status\":\"hidden\"}},\"config_version\":5}\n\n"
        );
        self::assertSame(5, $store->configVersion());
        self::assertSame('enabled', $store->getStatus('a'));
        self::assertSame('hidden', $store->getStatus('b'));
    }

    public function testCrlfLineEndingsHandled(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: features_updated\r\ndata: {\"features\":{\"z\":{\"status\":\"beta\"}},\"config_version\":2}\r\n\r\n");
        self::assertSame('beta', $store->getStatus('z'));
        self::assertSame(2, $store->configVersion());
    }

    public function testDataLeadingSpaceStripped(): void
    {
        // SSE spec: exactly one leading space after "data:" is removed.
        $store = new ArrayStore();
        $c = $this->consumer($store);
        $this->feed($c, "event: features_updated\ndata: {\"features\":{},\"config_version\":8}\n\n");
        self::assertSame(8, $store->configVersion());
    }

    /**
     * The reconnect-policy classifier. The bug this guards: a completed transfer
     * (curl_exec true) with a NON-200 status (429 connection-cap / 5xx / 404) must
     * be 'retry' (→ backoff), NOT 'clean' (which reset attempt=0 → reconnect storm
     * against a backend already at its cap).
     *
     * @dataProvider classifyCases
     */
    public function testClassifyResult(bool $ok, int $status, bool $stopped, string $expected): void
    {
        $m = new \ReflectionMethod(SseConsumer::class, 'classifyResult');
        self::assertSame($expected, $m->invoke(null, $ok, $status, $stopped));
    }

    /** @return array<string, array{0: bool, 1: int, 2: bool, 3: string}> */
    public static function classifyCases(): array
    {
        return [
            'clean 200 close'        => [true, 200, false, 'clean'],
            '429 connection cap'     => [true, 429, false, 'retry'],   // the storm bug
            '500 server error'       => [true, 500, false, 'retry'],
            '404 not found'          => [true, 404, false, 'retry'],
            '401 terminal'           => [true, 401, false, 'terminal'],
            '403 terminal'           => [true, 403, false, 'terminal'],
            '401 terminal even if ok false' => [false, 401, false, 'terminal'],
            'network error → retry'  => [false, 0, false, 'retry'],
            'graceful stop'          => [false, 0, true, 'stop'],
        ];
    }
}
