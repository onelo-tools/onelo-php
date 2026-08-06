<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Features\ArrayStore;
use Onelo\Monitor\Breadcrumb;
use Onelo\Monitor\MonitorClient;
use Onelo\Monitor\Scrub;
use Onelo\Onelo;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Version;
use PHPUnit\Framework\TestCase;

final class MonitorClientTest extends TestCase
{
    private ?MonitorClient $monitor = null;
    private FakeTransport $fake;

    protected function tearDown(): void
    {
        // Always tear down the transport so the registered shutdown drain is a no-op.
        $this->monitor?->close();
        $this->monitor = null;
    }

    /** Build a monitor over a 200-returning fake transport, global handlers off. */
    private function monitor(array $initOptions = []): MonitorClient
    {
        $this->fake = FakeTransport::json(200, ['ok' => true]);
        $client = new Onelo(
            secretKey: 'onelo_sk_test_abc123',
            apiUrl: 'https://api.test.onelo.tools',
            transport: $this->fake,
        );
        $this->monitor = $client->monitor;
        $this->monitor->init(array_merge(['installGlobalHandlers' => false], $initOptions));
        // init() now auto-emits one unconditional `session_opened` baseline event
        // (cold-start visibility fix). Drain + discard it here so per-test
        // assertions (firstEvent(), call counts) reflect only what the test itself
        // emits — same treatment as the JS/browser SDK's equivalent test fixup.
        $this->monitor->flush();
        $this->fake->calls = 0;
        $this->fake->lastBody = null;

        return $this->monitor;
    }

    /** Decode the first event of the last POSTed batch. */
    private function firstEvent(): array
    {
        $payload = json_decode((string) $this->fake->lastBody, true);
        self::assertIsArray($payload, 'no batch was POSTed');

        return $payload['events'][0];
    }

    // ── lifecycle / validation ──────────────────────────────────────────────

    public function testInitRejectsOutOfRangeSampleRate(): void
    {
        $client = new Onelo(secretKey: 'onelo_sk_test_x', apiUrl: 'https://api.test.onelo.tools', transport: FakeTransport::json(200, []));
        $this->expectException(\InvalidArgumentException::class);
        $client->monitor->init(['sampleRate' => 2.0]);
    }

    public function testIsInitialised(): void
    {
        $m = $this->monitor();
        self::assertTrue($m->isInitialised());
        $m->close();
        self::assertFalse($m->isInitialised());
    }

    public function testEmitBeforeInitIsDropped(): void
    {
        $client = new Onelo(secretKey: 'onelo_sk_test_x', apiUrl: 'https://api.test.onelo.tools', transport: FakeTransport::json(200, []));
        // Not initialised — capture must be a silent no-op, not a crash.
        $client->monitor->captureMessage('hello');
        $client->monitor->flush();
        self::assertTrue(true);
    }

    public function testEnvironmentFallsBackToEnvironmentVar(): void
    {
        putenv('ONELO_ENVIRONMENT'); // ensure the higher-precedence var is unset
        putenv('ENVIRONMENT=from-environment-var');
        try {
            $m = $this->monitor(); // no explicit environment option
            $m->captureMessage('x');
            $m->flush();
            self::assertSame('from-environment-var', $this->firstEvent()['meta']['environment'] ?? null);
        } finally {
            putenv('ENVIRONMENT');
        }
    }

    public function testOneloEnvironmentWinsOverEnvironmentVar(): void
    {
        putenv('ENVIRONMENT=generic');
        putenv('ONELO_ENVIRONMENT=onelo-specific');
        try {
            $m = $this->monitor();
            $m->captureMessage('x');
            $m->flush();
            self::assertSame('onelo-specific', $this->firstEvent()['meta']['environment'] ?? null);
        } finally {
            putenv('ENVIRONMENT');
            putenv('ONELO_ENVIRONMENT');
        }
    }

    public function testCaptureErrorsRecordsPhpWarningAsBreadcrumb(): void
    {
        // Install a no-op handler first so the monitor's onError (installed next)
        // chains to IT — not PHPUnit's warning-recorder — keeping the suite clean.
        set_error_handler(static fn (): bool => true);
        $m = $this->monitor(['captureErrors' => true]);

        // PHPUnit restricts error_reporting; force E_ALL so the level isn't treated
        // as suppressed (onError correctly honours error_reporting()).
        $prevReporting = error_reporting(E_ALL);
        try {
            // onError records the breadcrumb, then chains to the (no-op) previous.
            $m->onError(E_USER_WARNING, 'warn probe', 'app.php', 7);
        } finally {
            error_reporting($prevReporting);
        }

        $m->captureMessage('after');
        $m->flush();
        self::assertStringContainsString('warn probe', json_encode($this->firstEvent()['meta']['breadcrumbs'] ?? []));

        $m->close();             // pops onError → back to our no-op
        $this->monitor = null;   // prevent tearDown double-close
        restore_error_handler(); // pop our no-op → back to PHPUnit's handler
    }

    // ── capture API → wire shape ────────────────────────────────────────────

    public function testCaptureExceptionEmitsErrorEventWithStack(): void
    {
        $m = $this->monitor();
        $m->captureException(new \RuntimeException('kaboom'), 'checkout');
        $m->flush();
        $e = $this->firstEvent();
        self::assertSame('checkout', $e['featureName']);
        self::assertFalse($e['ok']);
        self::assertSame('kaboom', $e['error']);
        self::assertSame('RuntimeException', $e['meta']['exception']['type']);
        self::assertSame('RuntimeException', $e['meta']['error_type']);
        self::assertNotEmpty($e['meta']['exception']['frames']);
    }

    public function testCaptureMessageInfoIsOkEvent(): void
    {
        $m = $this->monitor();
        $m->captureMessage('just fyi', 'info', 'diag');
        $m->flush();
        $e = $this->firstEvent();
        self::assertTrue($e['ok']);
        self::assertArrayNotHasKey('error', $e);
        self::assertSame('info', $e['meta']['level']);
    }

    public function testCaptureMessageErrorIsErrorEvent(): void
    {
        $m = $this->monitor();
        $m->captureMessage('bad thing', 'error', 'diag');
        $m->flush();
        $e = $this->firstEvent();
        self::assertFalse($e['ok']);
        self::assertSame('bad thing', $e['error']);
    }

    public function testTrackSuccessEmitsDurationEvent(): void
    {
        $m = $this->monitor();
        $result = $m->track('work', fn () => 21 * 2);
        $m->flush();
        self::assertSame(42, $result);
        $e = $this->firstEvent();
        self::assertTrue($e['ok']);
        self::assertSame('track', $e['source']);
        self::assertArrayHasKey('durationMs', $e);
        self::assertGreaterThanOrEqual(0, $e['durationMs']);
    }

    public function testTrackFailureEmitsAndRethrows(): void
    {
        $m = $this->monitor();
        try {
            $m->track('work', function (): void {
                throw new \LogicException('nope');
            });
            self::fail('track must re-throw');
        } catch (\LogicException $ex) {
            self::assertSame('nope', $ex->getMessage());
        }
        $m->flush();
        $e = $this->firstEvent();
        self::assertFalse($e['ok']);
        self::assertSame('track', $e['source']);
        self::assertSame('nope', $e['error']);
        self::assertSame('LogicException', $e['meta']['exception']['type']);
    }

    // ── emit pipeline enrichment ────────────────────────────────────────────

    public function testScopeUserAndTagsAppliedToEvent(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'u_42']);
        $m->setTag('region', 'eu');
        $m->addBreadcrumb(Breadcrumb::info('opened'));
        $m->captureMessage('hi');
        $m->flush();
        $e = $this->firstEvent();
        self::assertSame('u_42', $e['userId']);
        self::assertSame('eu', $e['meta']['tags']['region']);
        self::assertSame('opened', $e['meta']['breadcrumbs'][0]['message']);
    }

    public function testSessionIdAttached(): void
    {
        $m = $this->monitor();
        $m->captureMessage('hi');
        $m->flush();
        $e = $this->firstEvent();
        self::assertNotEmpty($e['sessionId']);
        self::assertSame(32, strlen($e['sessionId'])); // bin2hex(16 bytes)
    }

    public function testSdkMetaStamped(): void
    {
        $m = $this->monitor();
        $m->captureMessage('hi');
        $m->flush();
        $e = $this->firstEvent();
        self::assertSame('onelo-php', $e['meta']['sdk']['name']);
        self::assertSame(Version::VERSION, $e['meta']['sdk']['version']);
    }

    public function testDeployContextOnCanonicalMetaPaths(): void
    {
        $m = $this->monitor(['environment' => 'staging', 'release' => 'sha_abc']);
        $m->captureMessage('hi');
        $m->flush();
        $e = $this->firstEvent();
        self::assertSame('staging', $e['meta']['environment']);
        self::assertSame('sha_abc', $e['meta']['app']['version']);
    }

    // ── flag correlation (D11) + server_name (D10) ────────────────────────────

    public function testFlagProviderStampsActiveFeatureFlagsOntoEvents(): void
    {
        // D11: the monitor auto-wires the Features cache snapshot, so every event
        // carries the live {name: status} flag state — the flag↔error correlation.
        $fake = FakeTransport::json(200, ['ok' => true]);
        $store = new ArrayStore();
        $store->replaceAll(['chat' => ['status' => 'enabled'], 'beta-x' => ['status' => 'beta']], 1);
        $client = new Onelo(
            secretKey: 'onelo_sk_test_abc123',
            apiUrl: 'https://api.test.onelo.tools',
            transport: $fake,
            featureStore: $store,
            featureAutoRefresh: false,
        );
        $m = $client->monitor;
        $m->init(['installGlobalHandlers' => false]);
        $m->flush(); // drain the auto-emitted `session_opened` baseline event
        $m->captureMessage('probe');
        $m->flush();
        $flags = (json_decode((string) $fake->lastBody, true))['events'][0]['meta']['flags'] ?? null;
        self::assertSame(['chat' => 'enabled', 'beta-x' => 'beta'], $flags);
        $m->close();
    }

    public function testSetFlagProviderOverridesTheSnapshotSource(): void
    {
        // Standalone-monitor path (no Features) / custom provider — parity with
        // Python's monitor.set_flag_provider.
        $m = $this->monitor();
        $m->setFlagProvider(static fn (): array => ['dark-mode' => 'enabled']);
        $m->captureMessage('probe');
        $m->flush();
        self::assertSame(['dark-mode' => 'enabled'], $this->firstEvent()['meta']['flags'] ?? null);
    }

    public function testServerNameStampedFromInitOption(): void
    {
        $m = $this->monitor(['serverName' => 'pod-7']);
        $m->captureMessage('probe');
        $m->flush();
        self::assertSame('pod-7', $this->firstEvent()['meta']['tags']['server_name'] ?? null);
    }

    public function testServerNameFallsBackToHostnameEnv(): void
    {
        $prevHost = getenv('HOSTNAME');
        putenv('POD_NAME'); // ensure the lower-precedence var is unset
        putenv('HOSTNAME=worker-42');
        try {
            $m = $this->monitor(); // no serverName option → env fallback
            $m->captureMessage('probe');
            $m->flush();
            self::assertSame('worker-42', $this->firstEvent()['meta']['tags']['server_name'] ?? null);
        } finally {
            putenv($prevHost === false ? 'HOSTNAME' : "HOSTNAME=$prevHost");
        }
    }

    // ── cross-process scope propagation — carrier/continueTrace (D12) ──────────

    public function testCarrierRoundTripRestoresUserIdAndTags(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'u1', 'email' => 'secret@x.co']);
        $m->setTag('region', 'eu');
        $carrier = $m->carrier();
        // Privacy whitelist: user.id ONLY (never email), plus tags + trace_id.
        self::assertSame(['id' => 'u1'], $carrier['user']);
        self::assertSame('eu', $carrier['tags']['region']);
        self::assertArrayHasKey('trace_id', $carrier);

        $result = $m->continueTrace($carrier, function () use ($m): string {
            $m->captureMessage('inside-job');

            return 'done';
        });
        self::assertSame('done', $result);
        $m->flush();
        $e = $this->firstEvent();
        self::assertSame('u1', $e['userId']);
        self::assertSame('eu', $e['meta']['tags']['region']);
        self::assertSame($carrier['trace_id'], $e['meta']['tags']['trace_id']);
    }

    public function testCarrierHmacSignedVerifiesAndApplies(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'u9']);
        $signed = $m->carrier(key: 'shared-key');
        self::assertArrayHasKey('_data', $signed);
        self::assertArrayHasKey('_sig', $signed);
        $m->continueTrace($signed, fn () => $m->captureMessage('x'), key: 'shared-key');
        $m->flush();
        self::assertSame('u9', $this->firstEvent()['userId']);
    }

    public function testCarrierTamperedPayloadRejected(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'victim']);
        $signed = $m->carrier(key: 'k');
        $signed['_data']['user']['id'] = 'attacker'; // tamper without re-signing
        $m->continueTrace($signed, fn () => $m->captureMessage('x'), key: 'k');
        $m->flush();
        // HMAC mismatch → rejected → empty scope → no user attribution.
        self::assertArrayNotHasKey('userId', $this->firstEvent());
    }

    public function testSignedCarrierWithoutKeyRejected(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'u']);
        $signed = $m->carrier(key: 'k');
        $m->continueTrace($signed, fn () => $m->captureMessage('x')); // no key to verify
        $m->flush();
        self::assertArrayNotHasKey('userId', $this->firstEvent());
    }

    public function testContinueTraceRestoresPreviousScope(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'outer']);
        $m->continueTrace(['user' => ['id' => 'inner']], fn () => $m->captureMessage('inside'));
        $m->captureMessage('after');
        $m->flush();
        $events = (json_decode((string) $this->fake->lastBody, true))['events'];
        self::assertSame('inner', $events[0]['userId']); // inside the block
        self::assertSame('outer', $events[1]['userId']); // scope restored afterwards
    }

    public function testScrubbingAtBoundary(): void
    {
        $m = $this->monitor();
        $m->captureMessage('token is Bearer supersecret_abc', 'error', 'diag', meta: ['password' => 'hunter2']);
        $m->flush();
        $e = $this->firstEvent();
        self::assertStringContainsString('[REDACTED]', $e['error']);
        self::assertSame('[REDACTED]', $e['meta']['password']);
        self::assertContains('password', $e['meta']['_onelo_redacted']);
    }

    // ── sampling ────────────────────────────────────────────────────────────

    public function testErrorSampleRateZeroDropsErrors(): void
    {
        $m = $this->monitor(['sampleRate' => 0.0]);
        $m->captureException(new \RuntimeException('x'));
        $m->flush();
        self::assertSame(0, $this->fake->calls); // nothing buffered → nothing sent
    }

    public function testSuccessSampleRateZeroDropsSuccessKeepsErrors(): void
    {
        $m = $this->monitor(['successSampleRate' => 0.0]);
        $m->track('ok-work', fn () => 1);          // ok=true → dropped
        $m->captureException(new \RuntimeException('x')); // ok=false → kept (errorSampleRate=1)
        $m->flush();
        $payload = json_decode((string) $this->fake->lastBody, true);
        self::assertCount(1, $payload['events']);
        self::assertFalse($payload['events'][0]['ok']);
    }

    public function testCloseResetsStaticScrubConfigSymmetrically(): void
    {
        $m = $this->monitor(['sensitiveHeaders' => ['x-custom-sig'], 'strictEmailScrub' => true]);
        self::assertTrue(Scrub::isPiiKey('x-custom-sig'));
        $m->close();
        // Both static knobs reset — no policy leaks into a persistent process.
        self::assertFalse(Scrub::isPiiKey('x-custom-sig'));
    }

    public function testZeroBufferSizeClampedStillDeliversEvent(): void
    {
        $m = $this->monitor(['bufferSize' => 0]); // clamped to 1 — no degenerate loss
        $m->captureMessage('hi', 'info', 'diag');
        $m->flush();
        self::assertSame('diag', $this->firstEvent()['featureName']);
    }

    public function testResetScopeClearsUser(): void
    {
        $m = $this->monitor();
        $m->setUser(['id' => 'u_1']);
        $m->resetScope();
        $m->captureMessage('hi');
        $m->flush();
        $e = $this->firstEvent();
        self::assertArrayNotHasKey('userId', $e);
    }
}
