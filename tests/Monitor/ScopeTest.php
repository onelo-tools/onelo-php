<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\Breadcrumb;
use Onelo\Monitor\MonitorEvent;
use Onelo\Monitor\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function testAppliesUserTagsContextsExtraBreadcrumbs(): void
    {
        $s = new Scope();
        $s->setUser(['id' => 'u_1', 'email' => 'e@x.co']);
        $s->setTag('region', 'eu');
        $s->setContext('device', ['os' => 'linux']);
        $s->setExtra('build', 42);
        $s->addBreadcrumb(Breadcrumb::info('step-1'));

        $e = MonitorEvent::make('f', true);
        $s->applyTo($e);

        self::assertSame('u_1', $e->userId);
        self::assertSame('eu', $e->meta['tags']['region']);
        self::assertSame(['os' => 'linux'], $e->meta['contexts']['device']);
        self::assertSame(42, $e->meta['extra']['build']);
        self::assertCount(1, $e->meta['breadcrumbs']);
        self::assertSame('step-1', $e->meta['breadcrumbs'][0]['message']);
    }

    public function testEventUserIdNotOverwritten(): void
    {
        $s = new Scope();
        $s->setUser(['id' => 'scope_user']);
        $e = MonitorEvent::make('f', true);
        $e->userId = 'explicit_user';
        $s->applyTo($e);
        self::assertSame('explicit_user', $e->userId);
    }

    public function testZeroUserIdIsValidNotSkipped(): void
    {
        $s = new Scope();
        $s->setUser(['id' => 0]);
        $e = MonitorEvent::make('f', true);
        $s->applyTo($e);
        self::assertSame('0', $e->userId); // 0 is a valid opaque id, cast to string
    }

    public function testNonArrayMetaBucketIsLeftUntouched(): void
    {
        $s = new Scope();
        $s->setTag('region', 'eu');
        $e = MonitorEvent::make('f', true, meta: ['tags' => 'not-an-array']);
        $s->applyTo($e); // must not throw / clobber
        self::assertSame('not-an-array', $e->meta['tags']);
    }

    public function testTagsMergeWithExistingMetaTags(): void
    {
        $s = new Scope();
        $s->setTag('region', 'eu');
        $e = MonitorEvent::make('f', true, meta: ['tags' => ['existing' => 'x']]);
        $s->applyTo($e);
        self::assertSame('x', $e->meta['tags']['existing']);
        self::assertSame('eu', $e->meta['tags']['region']);
    }

    public function testBreadcrumbCapacityDropsOldest(): void
    {
        $s = new Scope(breadcrumbCapacity: 2);
        $s->addBreadcrumb(Breadcrumb::info('a'));
        $s->addBreadcrumb(Breadcrumb::info('b'));
        $s->addBreadcrumb(Breadcrumb::info('c'));
        $e = MonitorEvent::make('f', true);
        $s->applyTo($e);
        self::assertCount(2, $e->meta['breadcrumbs']);
        self::assertSame('b', $e->meta['breadcrumbs'][0]['message']);
        self::assertSame('c', $e->meta['breadcrumbs'][1]['message']);
    }

    public function testResetClearsEverything(): void
    {
        $s = new Scope();
        $s->setUser(['id' => 'u']);
        $s->setTag('a', 'b');
        $s->addBreadcrumb(Breadcrumb::info('x'));
        $s->reset();
        $e = MonitorEvent::make('f', true);
        $s->applyTo($e);
        self::assertNull($e->userId);
        self::assertArrayNotHasKey('tags', $e->meta);
        self::assertArrayNotHasKey('breadcrumbs', $e->meta);
    }
}
