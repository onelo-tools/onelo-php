<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Onelo\Psr15\OneloAuthMiddleware;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Psr15MiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this->factory) implements RequestHandlerInterface {
            public ?ServerRequestInterface $received = null;

            public function __construct(private Psr17Factory $f)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return $this->f->createResponse(200);
            }
        };
    }

    private function request(?string $auth = null, array $query = []): ServerRequest
    {
        $req = new ServerRequest('GET', 'http://x/me');
        if ($auth !== null) {
            $req = $req->withHeader('Authorization', $auth);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }

        return $req;
    }

    public function testAttachesUserOnSuccess(): void
    {
        $mw = new OneloAuthMiddleware(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), $this->factory);
        $handler = $this->handler();
        $resp = $mw->process($this->request('Bearer tok'), $handler);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($handler->received);
        self::assertSame('user_1', $handler->received->getAttribute('oneloUser')->id);
    }

    public function test401OnMissingToken(): void
    {
        $mw = new OneloAuthMiddleware(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), $this->factory);
        $resp = $mw->process($this->request(), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
        $resp->getBody()->rewind();
        self::assertStringContainsString('missing_token', (string) $resp->getBody());
    }

    public function test503OnOutage(): void
    {
        $mw = new OneloAuthMiddleware(
            Fixtures::secretClient(FakeTransport::json(500, ['error' => 'x'])),
            $this->factory,
            options: ['retryAttempts' => 1],
        );
        $resp = $mw->process($this->request('Bearer tok'), $this->handler());
        self::assertSame(503, $resp->getStatusCode());
    }

    public function testOptionalDegrades401ToNullUser(): void
    {
        $mw = new OneloAuthMiddleware(
            Fixtures::secretClient(FakeTransport::json(401, ['error' => 'x'])),
            $this->factory,
            optional: true,
        );
        $handler = $this->handler();
        $resp = $mw->process($this->request('Bearer bad'), $handler);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($handler->received);
        self::assertNull($handler->received->getAttribute('oneloUser'));
    }

    public function testOptionalStillSurfaces503(): void
    {
        $mw = new OneloAuthMiddleware(
            Fixtures::secretClient(FakeTransport::json(500, ['error' => 'x'])),
            $this->factory,
            optional: true,
            options: ['retryAttempts' => 1],
        );
        $resp = $mw->process($this->request('Bearer tok'), $this->handler());
        self::assertSame(503, $resp->getStatusCode());
    }

    public function testAcceptsQueryToken(): void
    {
        $mw = new OneloAuthMiddleware(
            Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)),
            $this->factory,
            options: ['acceptQueryToken' => true],
        );
        $handler = $this->handler();
        $resp = $mw->process($this->request(null, ['token' => 'qtok']), $handler);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($handler->received);
        self::assertSame('user_1', $handler->received->getAttribute('oneloUser')->id);
    }
}
