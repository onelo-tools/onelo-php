<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\Feature;
use PHPUnit\Framework\TestCase;

final class FeatureTest extends TestCase
{
    public function testEnabledStatuses(): void
    {
        foreach (['enabled', 'new', 'beta'] as $s) {
            self::assertTrue(Feature::fromWire('f', ['status' => $s])->isEnabled(), $s);
        }
        foreach (['hidden', 'greyed', 'upsell', 'coming_soon'] as $s) {
            self::assertFalse(Feature::fromWire('f', ['status' => $s])->isEnabled(), $s);
        }
    }

    public function testVisibleStatuses(): void
    {
        foreach (['enabled', 'new', 'beta', 'coming_soon', 'greyed', 'upsell'] as $s) {
            self::assertTrue(Feature::fromWire('f', ['status' => $s])->isVisible(), $s);
        }
        self::assertFalse(Feature::fromWire('f', ['status' => 'hidden'])->isVisible());
    }

    public function testUnknownStatusFailsClosed(): void
    {
        // A status a newer backend added → not visible, not enabled, not known.
        $f = Feature::fromWire('f', ['status' => 'quantum']);
        self::assertFalse($f->isVisible());
        self::assertFalse($f->isEnabled());
        self::assertFalse($f->isKnown());
    }

    public function testKnownStatuses(): void
    {
        self::assertTrue(Feature::fromWire('f', ['status' => 'hidden'])->isKnown());
        self::assertTrue(Feature::fromWire('f', ['status' => 'enabled'])->isKnown());
        self::assertFalse(Feature::fromWire('f', ['status' => 'typo'])->isKnown());
    }

    public function testStatePredicates(): void
    {
        self::assertTrue(Feature::fromWire('f', ['status' => 'greyed'])->isGreyed());
        self::assertTrue(Feature::fromWire('f', ['status' => 'new'])->isNew());
        self::assertTrue(Feature::fromWire('f', ['status' => 'beta'])->isBeta());
        self::assertTrue(Feature::fromWire('f', ['status' => 'coming_soon'])->isComingSoon());
        self::assertTrue(Feature::fromWire('f', ['status' => 'upsell'])->isUpsell());
    }

    public function testUpgradeHintAndMetadata(): void
    {
        $f = Feature::fromWire('export', [
            'status' => 'upsell',
            'reason' => 'plan',
            'required_plan' => 'pro',
            'required_plan_label' => 'Pro',
            'upgrade_cta' => true,
        ]);
        self::assertSame('Pro', $f->upgradeHint());
        self::assertSame('pro', $f->requiredPlan);
        self::assertSame('plan', $f->reason);
        self::assertTrue($f->upgradeCta);
    }

    public function testUpgradeHintNullWhenNoLabel(): void
    {
        self::assertNull(Feature::fromWire('f', ['status' => 'enabled'])->upgradeHint());
        self::assertNull(Feature::fromWire('f', ['status' => 'upsell', 'required_plan_label' => ''])->upgradeHint());
    }

    public function testFromWireBareStringAndMissingStatus(): void
    {
        self::assertSame('enabled', Feature::fromWire('f', 'enabled')->status);       // bare string kept
        self::assertSame('hidden', Feature::fromWire('f', ['reason' => 'x'])->status); // missing status → hidden
        self::assertSame('42', Feature::fromWire('f', 42)->status);                    // non-string scalar → stringified
    }

    public function testFromWireIgnoresUnknownKeys(): void
    {
        // Forward-compat: extra keys don't break construction.
        $f = Feature::fromWire('f', ['status' => 'enabled', 'future_field' => ['a', 'b'], 'x' => 1]);
        self::assertSame('enabled', $f->status);
        self::assertTrue($f->isEnabled());
    }
}
