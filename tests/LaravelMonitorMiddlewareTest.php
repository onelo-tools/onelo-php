<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Onelo\Laravel\OneloMonitor;
use Onelo\Onelo;
use Onelo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LaravelMonitorMiddlewareTest extends TestCase
{
    /** A minimal duck-typed Illuminate request: method(), fullUrl(), headers->all(). */
    private function request(string $method, string $url, array $headers): object
    {
        return new class($method, $url, $headers) {
            public object $headers;

            public function __construct(private string $m, private string $u, array $h)
            {
                $this->headers = new class($h) {
                    /** @param array<string, string[]> $h */
                    public function __construct(private array $h)
                    {
                    }

                    /** @return array<string, string[]> */
                    public function all(): array
                    {
                        return $this->h;
                    }
                };
            }

            public function method(): string
            {
                return $this->m;
            }

            public function fullUrl(): string
            {
                return $this->u;
            }
        };
    }

    public function testCapturesThrownErrorAndRethrowsWithRequestContext(): void
    {
        $fake = FakeTransport::json(200, ['ok' => true]);
        $client = new Onelo(secretKey: 'onelo_sk_test_m', apiUrl: 'https://api.test.onelo.tools', transport: $fake);
        $client->monitor->init(['installGlobalHandlers' => false]);
        $client->monitor->flush(); // drain the auto-emitted `session_opened` baseline event
        $mw = new OneloMonitor($client);
        $req = $this->request('DELETE', 'http://x/thing?key=abc', [
            'Authorization' => ['Bearer s'],
            'X-Request-Id' => ['r1'],
        ]);

        try {
            $mw->handle($req, static fn () => throw new RuntimeException('laravel boom'));
            self::fail('the Laravel monitor middleware must re-throw');
        } catch (RuntimeException $e) {
            self::assertSame('laravel boom', $e->getMessage());
        }

        $client->monitor->flush();
        $event = json_decode((string) $fake->lastBody, true)['events'][0];
        self::assertSame('laravel boom', $event['error']);
        $ctx = $event['meta']['contexts']['request'];
        self::assertSame('DELETE', $ctx['method']);
        self::assertStringNotContainsString('abc', $ctx['url']);            // ?key= scrubbed
        self::assertArrayNotHasKey('authorization', $ctx['headers']);       // dropped
        self::assertSame('r1', $ctx['headers']['x-request-id']);            // survives (joined)
        $client->monitor->close();
    }
}
