<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\FeaturesClient;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class FeaturesClientTest extends TestCase
{
    private function client(FakeTransport $fake, ArrayStore $store, bool $autoRefresh): FeaturesClient
    {
        return new FeaturesClient(Fixtures::publishableClient($fake), $store, $autoRefresh);
    }

    /** Per-user calls (forUser, identify($id)) need the secret key (#520). */
    private function userClient(FakeTransport $fake): FeaturesClient
    {
        return new FeaturesClient(Fixtures::secretClient($fake), new ArrayStore(), false);
    }

    // ── cache-backed reads (no auto-refresh) ──────────────────────────────────

    public function testFeatureFailsClosedOnEmptyStore(): void
    {
        $f = $this->client(FakeTransport::json(200, []), new ArrayStore(), false);
        self::assertSame('hidden', $f->feature('anything')->status);
        self::assertFalse($f->isEnabled('anything'));
    }

    public function testFeatureReadsPopulatedStore(): void
    {
        $store = new ArrayStore();
        $store->replaceAll(['export' => ['status' => 'enabled']], 1);
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, $store, false);
        self::assertTrue($f->isEnabled('export'));
        self::assertSame(0, $fake->calls); // no auto-refresh → a store read makes no network call
    }

    // ── auto-refresh fallback (no daemon) ─────────────────────────────────────

    public function testAutoRefreshPollsOncePerRequest(): void
    {
        $fake = FakeTransport::json(200, ['config_version' => 2, 'features' => ['x' => ['status' => 'enabled']]]);
        $f = $this->client($fake, new ArrayStore(), true);
        self::assertTrue($f->isEnabled('x'));       // first read triggers GET /poll
        self::assertTrue($f->isEnabled('x'));       // second read reuses the store
        self::assertSame(1, $fake->calls);          // only ONE poll for the whole request
        self::assertStringContainsString('/api/sdk/features/poll', (string) $fake->lastUrl);
        // ADR-0003 / #105: the credential is the X-Publishable-Key header and
        // is NOT in the query string (it would land in the proxy access.log on
        // every poll, and the backend refuses `?key=` from 2027-03-31).
        self::assertSame('onelo_pk_test_abc123', $fake->lastHeaders['X-Publishable-Key'] ?? null);
        self::assertStringNotContainsString('key=', (string) $fake->lastUrl);
        self::assertStringNotContainsString('onelo_pk_test_abc123', (string) $fake->lastUrl);
    }

    public function testReadyFallbackPolls(): void
    {
        $fake = FakeTransport::json(200, ['config_version' => 5, 'features' => ['x' => ['status' => 'enabled']]]);
        $f = $this->client($fake, new ArrayStore(), true);
        self::assertTrue($f->ready());
        self::assertSame(1, $fake->calls);
    }

    public function testReadyShortCircuitsWhenStorePopulated(): void
    {
        $store = new ArrayStore();
        $store->replaceAll(['x' => ['status' => 'enabled']], 9);
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, $store, false);
        self::assertTrue($f->ready());
        self::assertSame(0, $fake->calls); // no network — already ready
    }

    public function testPollFailClosedLeavesStoreEmpty(): void
    {
        $f = $this->client(FakeTransport::throwing(), new ArrayStore(), true);
        self::assertSame('hidden', $f->feature('x')->status); // poll threw → store stays empty → hidden
    }

    // ── per-user resolve ──────────────────────────────────────────────────────

    public function testForUserResolvesAndCaches(): void
    {
        $fake = FakeTransport::json(200, ['features' => ['face-stream' => ['status' => 'enabled']]]);
        $f = $this->userClient($fake);
        $uf = $f->forUser('u1');
        self::assertTrue($uf->isEnabled('face-stream'));
        self::assertStringContainsString('/api/sdk/features/resolve', (string) $fake->lastUrl);
        $body = json_decode((string) $fake->lastBody, true);
        self::assertSame('u1', $body['userId']);
        self::assertSame('php', $body['sdk_platform']);
        // Second call for the same user is served from cache — no new request.
        $f->forUser('u1');
        self::assertSame(1, $fake->calls);
    }

    public function testForUserFailClosed(): void
    {
        $f = $this->userClient(FakeTransport::throwing());
        self::assertFalse($f->forUser('u1')->isEnabled('anything')); // network error → hidden
    }

    public function testForUserRejectsEmptyUserId(): void
    {
        $f = $this->client(FakeTransport::json(200, []), new ArrayStore(), false);
        $this->expectException(\InvalidArgumentException::class);
        $f->forUser('');
    }

    public function testInvalidateUserForcesReResolve(): void
    {
        $fake = FakeTransport::json(200, ['features' => ['x' => ['status' => 'enabled']]]);
        $f = $this->userClient($fake);
        $f->forUser('u1');
        $f->invalidateUser('u1');
        $f->forUser('u1');
        self::assertSame(2, $fake->calls); // cache dropped → second resolve hit the network
    }

    // ── discovery batch-ping ──────────────────────────────────────────────────

    public function testDeclareFlushesBatchPingWithInstanceHeader(): void
    {
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, new ArrayStore(), false);
        $f->declare(['a', 'b']);
        $f->flushDiscovery();
        self::assertSame(1, $fake->calls);
        self::assertStringContainsString('/api/sdk/features/batch-ping', (string) $fake->lastUrl);
        $body = json_decode((string) $fake->lastBody, true);
        self::assertEqualsCanonicalizing(['a', 'b'], $body['features']);
        self::assertSame('onelo_pk_test_abc123', $body['publishableKey']);
        self::assertArrayHasKey('X-Onelo-Instance-Id', $fake->lastHeaders);
    }

    public function testFlushDiscoveryNoopWhenNothingPending(): void
    {
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, new ArrayStore(), false);
        $f->flushDiscovery();
        self::assertSame(0, $fake->calls);
    }

    public function testFeatureReadSchedulesDiscovery(): void
    {
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, new ArrayStore(), false);
        $f->feature('checkout'); // first sighting → pending discovery
        $f->flushDiscovery();
        $body = json_decode((string) $fake->lastBody, true);
        self::assertContains('checkout', $body['features']);
    }

    public function testPollDiscoveryRequestedRePingsKnownSlugs(): void
    {
        // A prior batch-ping already sent 'a'. Then a /poll response with
        // discovery_requested must re-ping the known set (dashboard "Discover"
        // click) — parity with the SSE/daemon path.
        $fake = FakeTransport::sequence([
            [200, []],                                        // 1: batch-ping for declare(['a'])
            [200, ['up_to_date' => true, 'discovery_requested' => true]], // 2: the poll
            [200, []],                                        // 3: the re-ping at flushDiscovery
        ]);
        $f = $this->client($fake, new ArrayStore(), true);
        $f->declare(['a']);
        $f->flushDiscovery();          // call 1
        self::assertSame(1, $fake->calls);
        $f->feature('a');              // autoRefresh → GET /poll (call 2) sees discovery_requested
        $f->flushDiscovery();          // re-pings 'a' (call 3) because pinged was cleared
        self::assertSame(3, $fake->calls);
        self::assertStringContainsString('/api/sdk/features/batch-ping', (string) $fake->lastUrl);
    }

    public function testResetForRequestReenablesAutoRefresh(): void
    {
        $fake = FakeTransport::json(200, ['config_version' => 1, 'features' => ['x' => ['status' => 'enabled']]]);
        $f = $this->client($fake, new ArrayStore(), true);
        $f->feature('x');              // poll #1
        $f->feature('x');              // no new poll (guarded)
        self::assertSame(1, $fake->calls);
        $f->resetForRequest();
        $f->feature('x');              // poll #2 after reset
        self::assertSame(2, $fake->calls);
    }

    public function testDiscoveryPingsEachSlugOnlyOnce(): void
    {
        $fake = FakeTransport::json(200, []);
        $f = $this->client($fake, new ArrayStore(), false);
        $f->declare(['a']);
        $f->flushDiscovery();          // pings a
        $f->declare(['a', 'b']);
        $f->flushDiscovery();          // pings only b (a already sent)
        self::assertSame(2, $fake->calls);
        $body = json_decode((string) $fake->lastBody, true);
        self::assertSame(['b'], $body['features']);
    }

    // ── identify() churn guard (A5) ───────────────────────────────────────────

    public function testIdentifyChurnWarnsOnceAfterFiveDistinctSwitches(): void
    {
        // Capture error_log output to a temp file so we can assert the one-time
        // DX warning fires on the 5th distinct switch and not before/again.
        $log = tempnam(sys_get_temp_dir(), 'onelo-churn');
        self::assertIsString($log);
        $prev = ini_set('error_log', $log);
        try {
            $f = $this->userClient(FakeTransport::json(200, []));
            $needle = 'per-request identify()';

            // 4 distinct switches (null→u0→u1→u2→u3) — one below threshold.
            foreach (['u0', 'u1', 'u2', 'u3'] as $id) {
                $f->identify($id);
            }
            self::assertStringNotContainsString($needle, (string) file_get_contents($log));

            $f->identify('u4'); // 5th distinct switch → warns
            self::assertStringContainsString($needle, (string) file_get_contents($log));

            $f->identify('u5'); // further switches must NOT re-warn (once per process)
            self::assertSame(1, substr_count((string) file_get_contents($log), $needle));
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
            @unlink($log);
        }
    }

    public function testIdentifyRepeatingSameIdDoesNotCountAsChurn(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'onelo-churn');
        self::assertIsString($log);
        $prev = ini_set('error_log', $log);
        try {
            $f = $this->userClient(FakeTransport::json(200, []));
            for ($i = 0; $i < 10; $i++) {
                $f->identify('same-user'); // only the first is a switch; the rest are idempotent
            }
            self::assertStringNotContainsString('per-request identify()', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
            @unlink($log);
        }
    }
}
