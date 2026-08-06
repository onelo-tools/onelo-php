<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Onelo\Onelo;
use Onelo\Psr15\OneloMonitorMiddleware;
use Onelo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class Psr15MonitorMiddlewareTest extends TestCase
{
    private FakeTransport $fake;
    private Onelo $client;

    protected function setUp(): void
    {
        $this->fake = FakeTransport::json(200, ['ok' => true]);
        $this->client = new Onelo(
            secretKey: 'onelo_sk_test_m',
            apiUrl: 'https://api.test.onelo.tools',
            transport: $this->fake,
        );
        $this->client->monitor->init(['installGlobalHandlers' => false]);
        $this->client->monitor->flush(); // drain the auto-emitted `session_opened` baseline event
    }

    protected function tearDown(): void
    {
        $this->client->monitor->close();
    }

    private function throwingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('route boom');
            }
        };
    }

    private function statusHandler(int $status): RequestHandlerInterface
    {
        return new class($status) implements RequestHandlerInterface {
            public function __construct(private int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse($this->status);
            }
        };
    }

    /** @return array<string, mixed> */
    private function firstEvent(): array
    {
        $payload = json_decode((string) $this->fake->lastBody, true);
        self::assertIsArray($payload, 'no batch was POSTed');

        return $payload['events'][0];
    }

    public function testCapturesThrownErrorAndRethrowsWithRequestContext(): void
    {
        $mw = new OneloMonitorMiddleware($this->client);
        $req = (new ServerRequest('POST', 'http://x/pay?token=secret'))
            ->withHeader('Authorization', 'Bearer s3cr3t');

        try {
            $mw->process($req, $this->throwingHandler());
            self::fail('the monitor middleware must re-throw the route exception');
        } catch (RuntimeException $e) {
            self::assertSame('route boom', $e->getMessage());
        }

        $this->client->monitor->flush();
        $event = $this->firstEvent();
        self::assertSame('route boom', $event['error']);
        $ctx = $event['meta']['contexts']['request'] ?? null;
        self::assertIsArray($ctx);
        self::assertSame('POST', $ctx['method']);
        self::assertStringContainsString('/pay', $ctx['url']);
        self::assertStringNotContainsString('secret', $ctx['url']);      // query scrubbed
        self::assertArrayNotHasKey('authorization', $ctx['headers'] ?? []); // dropped
    }

    public function testTagsResponseStatus(): void
    {
        $mw = new OneloMonitorMiddleware($this->client);
        $resp = $mw->process(new ServerRequest('GET', 'http://x/ok'), $this->statusHandler(204));
        self::assertSame(204, $resp->getStatusCode());
        // A success path emits no event of its own; capture one to inspect the tag.
        $this->client->monitor->captureMessage('probe');
        $this->client->monitor->flush();
        self::assertSame('204', $this->firstEvent()['meta']['tags']['http.status_code'] ?? null);
    }
}
