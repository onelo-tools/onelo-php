<?php

declare(strict_types=1);

namespace Onelo\Tests;

use InvalidArgumentException;
use Onelo\Auth\InMemoryAuthCache;
use Onelo\Auth\OneloUser;
use PHPUnit\Framework\TestCase;

final class InMemoryAuthCacheTest extends TestCase
{
    private function user(string $id): OneloUser
    {
        return new OneloUser($id, "$id@x.co", false, null, [], []);
    }

    public function testRejectsMaxSizeBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InMemoryAuthCache(0);
    }

    public function testStoreAndRetrieve(): void
    {
        $c = new InMemoryAuthCache();
        $c->set('h1', $this->user('a'), 30);
        self::assertSame('a', $c->get('h1')?->id);
    }

    public function testTtlZeroDoesNotCache(): void
    {
        $c = new InMemoryAuthCache();
        $c->set('h1', $this->user('a'), 0);
        self::assertNull($c->get('h1'));
    }

    public function testExpires(): void
    {
        $c = new InMemoryAuthCache();
        $c->set('h1', $this->user('a'), 0.01); // 10ms
        self::assertSame('a', $c->get('h1')?->id);
        usleep(25_000); // 25ms
        self::assertNull($c->get('h1'));
    }

    public function testEvictsOldestOverCap(): void
    {
        $c = new InMemoryAuthCache(2);
        $c->set('h1', $this->user('a'), 30);
        $c->set('h2', $this->user('b'), 30);
        $c->set('h3', $this->user('c'), 30); // evicts h1
        self::assertNull($c->get('h1'));
        self::assertSame('b', $c->get('h2')?->id);
        self::assertSame('c', $c->get('h3')?->id);
    }

    public function testGetPromotesToMru(): void
    {
        $c = new InMemoryAuthCache(2);
        $c->set('h1', $this->user('a'), 30);
        $c->set('h2', $this->user('b'), 30);
        $c->get('h1'); // h1 becomes MRU, h2 oldest
        $c->set('h3', $this->user('c'), 30); // evicts h2
        self::assertSame('a', $c->get('h1')?->id);
        self::assertNull($c->get('h2'));
    }

    public function testInvalidate(): void
    {
        $c = new InMemoryAuthCache();
        $c->set('h1', $this->user('a'), 30);
        $c->invalidate('h1');
        self::assertNull($c->get('h1'));
    }
}
