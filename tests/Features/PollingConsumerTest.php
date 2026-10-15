<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\PollingConsumer;
use PHPUnit\Framework\TestCase;

/**
 * Wiring test for PollingConsumer::pollOnce() (#105, ADR-0003).
 *
 * The consumer drives raw curl inline (no Transport), so nothing between the
 * URL/header assembly and the socket could be observed before — putting the key
 * back into `?key=` would keep every other test green. The `$httpGet` seam
 * replaces the curl leg and hands back exactly what the wire would receive.
 */
final class PollingConsumerTest extends TestCase
{
    /** @var list<array{url: string, headers: list<string>, timeoutMs: int}> */
    private array $requests = [];

    private function consumer(ArrayStore $store, ?string $userId = null, array $reply = ['config_version' => 1, 'up_to_date' => true]): PollingConsumer
    {
        return new PollingConsumer(
            store: $store,
            key: 'onelo_sk_test_abc', // #520: a fixed userId needs the secret key
            apiUrl: 'https://api.test.onelo.tools/',
            requestTimeoutMs: 1234,
            featureEnvironment: 'test',
            userId: $userId,
            httpGet: function (string $url, array $headers, int $timeoutMs) use ($reply): array {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'timeoutMs' => $timeoutMs];

                return [json_encode($reply, JSON_THROW_ON_ERROR), 200, ''];
            },
        );
    }

    private function pollOnce(PollingConsumer $c): void
    {
        (new \ReflectionClass($c))->getMethod('pollOnce')->invoke($c);
    }

    public function testPollSendsTheKeyAsHeaderAndNeverInTheUrl(): void
    {
        $c = $this->consumer(new ArrayStore(), userId: 'u1');

        $this->pollOnce($c);

        self::assertCount(1, $this->requests);
        $req = $this->requests[0];
        self::assertStringStartsWith('https://api.test.onelo.tools/api/sdk/features/poll?', $req['url']);
        self::assertStringContainsString('since_version=0', $req['url']);
        self::assertStringContainsString('userId=u1', $req['url']);
        self::assertStringContainsString('environment=test', $req['url']);
        self::assertStringNotContainsString('key=', $req['url'], 'credential must not be in the query string');
        self::assertStringNotContainsString('onelo_sk_test_abc', $req['url']);
        self::assertContains('X-Publishable-Key: onelo_sk_test_abc', $req['headers']);
        // The transport headers the raw GET already sent are untouched.
        self::assertContains('Accept: application/json', $req['headers']);
        self::assertSame(1234, $req['timeoutMs']);
    }

    public function testPollFeedsTheStoreAndMarksFirstContact(): void
    {
        $store = new ArrayStore();
        $c = $this->consumer($store, reply: ['config_version' => 7, 'features' => ['x' => ['status' => 'enabled']]]);
        self::assertFalse($c->firstContact());

        $this->pollOnce($c);

        self::assertTrue($c->firstContact());
        self::assertSame(7, $store->configVersion());
    }
}
