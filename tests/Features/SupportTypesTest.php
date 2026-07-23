<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\DiscoveredNames;
use Onelo\Features\StrategyResolver;
use Onelo\Features\UserSnapshotCache;
use PHPUnit\Framework\TestCase;

final class SupportTypesTest extends TestCase
{
    // ── StrategyResolver ──────────────────────────────────────────────────────

    public function testStrategyResolverAutoMapsToSse(): void
    {
        self::assertSame('sse', StrategyResolver::resolve('auto'));
        self::assertSame('sse', StrategyResolver::resolve('sse'));
        self::assertSame('polling', StrategyResolver::resolve('polling'));
    }

    public function testStrategyResolverRejectsDeferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StrategyResolver::resolve('redis');
    }

    public function testStrategyResolverRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StrategyResolver::resolve('websocket');
    }

    // ── DiscoveredNames ───────────────────────────────────────────────────────

    public function testDiscoveredNamesDedup(): void
    {
        $d = new DiscoveredNames();
        self::assertTrue($d->add('a'));
        self::assertFalse($d->add('a'));     // already seen
        self::assertTrue($d->addMany(['a', 'b', 'c'])); // b,c new → true
        self::assertFalse($d->addMany(['a', 'b']));      // all seen → false
        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $d->snapshot());
    }

    // ── UserSnapshotCache ─────────────────────────────────────────────────────

    public function testUserCacheGetPut(): void
    {
        $c = new UserSnapshotCache();
        self::assertNull($c->get('u1'));
        $c->put('u1', ['x' => ['status' => 'enabled']], 30.0);
        self::assertSame(['x' => ['status' => 'enabled']], $c->get('u1'));
    }

    public function testUserCacheExpiryLazyPrune(): void
    {
        $c = new UserSnapshotCache();
        $c->put('u1', ['x' => ['status' => 'enabled']], 0.0); // already expired (deadline == now)
        usleep(1000);
        self::assertNull($c->get('u1'));
    }

    public function testUserCacheInvalidateAndClear(): void
    {
        $c = new UserSnapshotCache();
        $c->put('u1', ['a' => 1], 30.0);
        $c->put('u2', ['b' => 2], 30.0);
        $c->invalidate('u1');
        self::assertNull($c->get('u1'));
        self::assertNotNull($c->get('u2'));
        $c->clear();
        self::assertNull($c->get('u2'));
    }

    public function testUserCacheHardCapEviction(): void
    {
        $c = new UserSnapshotCache(maxEntries: 3);
        $c->put('u1', ['a' => 1], 100.0);
        $c->put('u2', ['a' => 1], 200.0);
        $c->put('u3', ['a' => 1], 300.0);
        $c->put('u4', ['a' => 1], 400.0); // over cap → evict soonest-to-expire (u1)
        self::assertNull($c->get('u1'));
        self::assertNotNull($c->get('u4'));
        self::assertLessThanOrEqual(3, iterator_count(new \ArrayIterator(array_filter(
            ['u1', 'u2', 'u3', 'u4'],
            static fn (string $u): bool => $c->get($u) !== null,
        ))));
    }
}
