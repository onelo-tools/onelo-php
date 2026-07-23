<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\MonitorEvent;
use PHPUnit\Framework\TestCase;

final class MonitorEventTest extends TestCase
{
    public function testMakeDefaults(): void
    {
        $e = MonitorEvent::make('checkout', true);
        self::assertSame('checkout', $e->featureName);
        self::assertTrue($e->ok);
        self::assertSame('event', $e->source);
        self::assertSame('php', $e->platform);
        self::assertNull($e->durationMs);
        self::assertNull($e->error);
    }

    public function testToWireOmitsNullFields(): void
    {
        $e = MonitorEvent::make('checkout', true);
        $wire = $e->toWire();
        self::assertSame(['featureName', 'ok', 'platform', 'source', 'ts'], array_keys($wire));
        self::assertArrayNotHasKey('durationMs', $wire);
        self::assertArrayNotHasKey('error', $wire);
        self::assertArrayNotHasKey('userId', $wire);
        self::assertArrayNotHasKey('sessionId', $wire);
        self::assertArrayNotHasKey('meta', $wire);
    }

    public function testToWireIncludesSetFields(): void
    {
        $e = MonitorEvent::make('checkout', false, error: 'boom', source: 'track', durationMs: 42, meta: ['k' => 'v']);
        $e->userId = 'u_9';
        $e->sessionId = 's_1';
        $wire = $e->toWire();
        self::assertSame(42, $wire['durationMs']);
        self::assertSame('boom', $wire['error']);
        self::assertSame('u_9', $wire['userId']);
        self::assertSame('s_1', $wire['sessionId']);
        self::assertSame(['k' => 'v'], $wire['meta']);
        self::assertFalse($wire['ok']);
    }

    public function testIsoTimestampFormat(): void
    {
        $e = new MonitorEvent('f', true, timestamp: 1_700_000_000.123);
        $wire = $e->toWire();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $wire['ts']);
        self::assertSame('2023-11-14T22:13:20.123Z', $wire['ts']);
    }

    public function testIsoTimestampRoundingCap(): void
    {
        // .9999 would round ms to 1000 — must clamp to 999, not overflow to next second.
        $e = new MonitorEvent('f', true, timestamp: 1_700_000_000.9999);
        self::assertStringEndsWith('.999Z', $e->toWire()['ts']);
    }
}
