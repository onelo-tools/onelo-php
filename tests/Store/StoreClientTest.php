<?php

declare(strict_types=1);

namespace Onelo\Tests\Store;

use Onelo\Onelo;
use Onelo\Store\OneloPlan;
use Onelo\Store\StoreException;
use Onelo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class StoreClientTest extends TestCase
{
    public function testListPlansSendsSecretKeyAndMaps(): void
    {
        $t = FakeTransport::json(200, ['plans' => [[
            'plan' => 'pro', 'name' => 'Pro', 'short_label' => null, 'mode' => 'subscription',
            'currency' => 'eur', 'price_amount' => 1900, 'annual_price_amount' => 19000,
            'trial_period_days' => 14, 'is_active' => true, 'sort_order' => 2,
        ]]]);
        $onelo = new Onelo(secretKey: 'onelo_sk_live_a', apiUrl: 'https://api.test', transport: $t);
        $plans = $onelo->store->listPlans();
        self::assertEquals([new OneloPlan('pro', 'Pro', null, 'subscription', 'eur', 1900, 19000, 14, true, 2)], $plans);
        self::assertSame('https://api.test/api/sdk/store/plans', $t->lastUrl);
        self::assertSame('onelo_sk_live_a', $t->lastHeaders['X-Onelo-Secret-Key']);
    }

    public function testPublishableKeyIsRefusedBeforeRequest(): void
    {
        $t = FakeTransport::json(200, ['plans' => []]);
        $onelo = new Onelo(publishableKey: 'onelo_pk_live_a', apiUrl: 'https://api.test', transport: $t);
        $this->expectException(\LogicException::class);
        try {
            $onelo->store->listPlans();
        } finally {
            self::assertSame(0, $t->calls);
        }
    }

    public function testRefusalRaisesStoreExceptionWithStatus(): void
    {
        $onelo = new Onelo(secretKey: 'onelo_sk_live_a', apiUrl: 'https://api.test', transport: FakeTransport::json(403, ['detail' => 'nope']));
        try {
            $onelo->store->listPlans();
            self::fail('expected StoreException');
        } catch (StoreException $e) {
            self::assertSame(403, $e->status);
        }
    }
}
