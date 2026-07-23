<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\Listener;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ListenerTest extends TestCase
{
    private function listener(string $strategy): Listener
    {
        return new Listener(
            Fixtures::publishableClient(FakeTransport::json(200, [])),
            new ArrayStore(),
            $strategy,
        );
    }

    public function testAutoResolvesToSse(): void
    {
        self::assertSame('sse', $this->listener('auto')->strategy());
    }

    public function testExplicitStrategies(): void
    {
        self::assertSame('sse', $this->listener('sse')->strategy());
        self::assertSame('polling', $this->listener('polling')->strategy());
    }

    public function testInvalidStrategyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->listener('redis');
    }

    public function testFirstContactStartsFalse(): void
    {
        self::assertFalse($this->listener('sse')->firstContact());
    }
}
