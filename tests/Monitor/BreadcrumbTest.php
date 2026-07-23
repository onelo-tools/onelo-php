<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\Breadcrumb;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    public function testInfoFactory(): void
    {
        $c = Breadcrumb::info('loaded', ['n' => 1]);
        self::assertSame('info', $c->category);
        self::assertSame('loaded', $c->message);
        self::assertSame(['n' => 1], $c->data);
    }

    public function testInfoWithoutDataOmitsData(): void
    {
        $c = Breadcrumb::info('loaded');
        self::assertNull($c->data);
        self::assertArrayNotHasKey('data', $c->toWire());
    }

    public function testHttpFactoryScrubsUrlEagerly(): void
    {
        $c = Breadcrumb::http('get', 'https://api.x.co/run?token=abc123&ok=1', 200, 12);
        self::assertSame('http', $c->category);
        self::assertStringContainsString('GET https://api.x.co/run?', $c->message);
        self::assertStringContainsString('token=%5BREDACTED%5D', $c->message);
        self::assertStringContainsString('ok=1', $c->message);
        self::assertSame('GET', $c->data['method']);
        self::assertStringContainsString('token=%5BREDACTED%5D', $c->data['url']);
        self::assertSame(200, $c->data['status']);
        self::assertSame(12, $c->data['duration_ms']);
    }

    public function testHttpOmitsNullStatusAndDuration(): void
    {
        $c = Breadcrumb::http('POST', 'https://api.x.co/run');
        self::assertArrayNotHasKey('status', $c->data);
        self::assertArrayNotHasKey('duration_ms', $c->data);
    }

    public function testToWireShape(): void
    {
        $c = new Breadcrumb('http', 'GET /x', 1_700_000_000.5, 'warning', ['a' => 1]);
        $wire = $c->toWire();
        self::assertSame('http', $wire['category']);
        self::assertSame('GET /x', $wire['message']);
        self::assertSame(1_700_000_000.5, $wire['ts']);
        self::assertSame('warning', $wire['level']);
        self::assertSame(['a' => 1], $wire['data']);
    }
}
