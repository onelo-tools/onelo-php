<?php

declare(strict_types=1);

// Stub Laravel's global `response()` helper inside the adapter's namespace so
// OneloAuthenticate::handle() resolves to THIS instead of Laravel — lets us test
// the adapter without pulling in illuminate/*.
namespace Onelo\Laravel {
    function response(): \Onelo\Tests\FakeResponseFactory
    {
        return new \Onelo\Tests\FakeResponseFactory();
    }
}

namespace Onelo\Tests {
    use Onelo\Laravel\OneloAuthenticate;
    use Onelo\Tests\Support\FakeTransport;
    use Onelo\Tests\Support\Fixtures;
    use PHPUnit\Framework\TestCase;

    final class FakeResponseFactory
    {
        public function json(mixed $data, int $status): FakeJsonResponse
        {
            return new FakeJsonResponse($data, $status);
        }
    }

    final class FakeJsonResponse
    {
        public function __construct(public readonly mixed $data, public readonly int $status)
        {
        }
    }

    final class FakeAttributeBag
    {
        /** @var array<string, mixed> */
        private array $bag = [];

        public function set(string $key, mixed $value): void
        {
            $this->bag[$key] = $value;
        }

        public function get(string $key): mixed
        {
            return $this->bag[$key] ?? null;
        }
    }

    /** Minimal stand-in for Illuminate\Http\Request (header/query/attributes). */
    final class FakeLaravelRequest
    {
        public FakeAttributeBag $attributes;

        /** @param array<string, string> $query */
        public function __construct(private readonly ?string $auth = null, private readonly array $query = [])
        {
            $this->attributes = new FakeAttributeBag();
        }

        public function header(string $name): ?string
        {
            return strtolower($name) === 'authorization' ? $this->auth : null;
        }

        public function query(string $name): ?string
        {
            return $this->query[$name] ?? null;
        }
    }

    final class LaravelMiddlewareTest extends TestCase
    {
        public function testAttachesUserOnSuccessAndCallsNext(): void
        {
            $mw = new OneloAuthenticate(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)));
            $req = new FakeLaravelRequest('Bearer tok');
            $result = $mw->handle($req, fn ($r) => 'NEXT');
            self::assertSame('NEXT', $result);
            self::assertSame('user_1', $req->attributes->get('oneloUser')->id);
        }

        public function test401OnMissingToken(): void
        {
            $mw = new OneloAuthenticate(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)));
            $result = $mw->handle(new FakeLaravelRequest(null), fn ($r) => 'NEXT');
            self::assertInstanceOf(FakeJsonResponse::class, $result);
            self::assertSame(401, $result->status);
            self::assertSame(['error' => 'missing_token'], $result->data);
        }

        public function test503OnOutage(): void
        {
            $mw = new OneloAuthenticate(
                Fixtures::secretClient(FakeTransport::json(500, ['error' => 'x'])),
                options: ['retryAttempts' => 1],
            );
            $result = $mw->handle(new FakeLaravelRequest('Bearer tok'), fn ($r) => 'NEXT');
            self::assertInstanceOf(FakeJsonResponse::class, $result);
            self::assertSame(503, $result->status);
        }

        public function testOptionalDegrades401ToNull(): void
        {
            $mw = OneloAuthenticate::optional(Fixtures::secretClient(FakeTransport::json(401, ['error' => 'x'])));
            $req = new FakeLaravelRequest('Bearer bad');
            $result = $mw->handle($req, fn ($r) => 'NEXT');
            self::assertSame('NEXT', $result);
            self::assertNull($req->attributes->get('oneloUser'));
        }

        public function testOptionalStillSurfaces503(): void
        {
            $mw = OneloAuthenticate::optional(
                Fixtures::secretClient(FakeTransport::json(500, ['error' => 'x'])),
                ['retryAttempts' => 1],
            );
            $result = $mw->handle(new FakeLaravelRequest('Bearer tok'), fn ($r) => 'NEXT');
            self::assertInstanceOf(FakeJsonResponse::class, $result);
            self::assertSame(503, $result->status);
        }
    }
}
