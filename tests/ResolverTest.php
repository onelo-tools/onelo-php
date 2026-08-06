<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Onelo\Auth\AuthHttpError;
use Onelo\Auth\Resolver;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private function catchAuth(callable $fn): AuthHttpError
    {
        try {
            $fn();
        } catch (AuthHttpError $e) {
            return $e;
        }
        self::fail('expected AuthHttpError');
    }

    public function testThrowsForPublishableKey(): void
    {
        $this->expectExceptionMessage('publishable key');
        new Resolver(Fixtures::publishableClient(FakeTransport::json(200, Fixtures::OK_USER)));
    }

    public function testResolvesValidTokenAndEmitsVerify(): void
    {
        $events = [];
        $r = new Resolver(
            Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)),
            onAuthEvent: function (?string $id, string $e) use (&$events): void {
                $events[] = [$id, $e];
            },
        );
        $user = $r->resolve('Bearer tok');
        self::assertSame('user_1', $user->id);
        self::assertContains(['user_1', 'auth.verify'], $events);
    }

    public function testMissingToken401(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)));
        $e = $this->catchAuth(fn () => $r->resolve(null));
        self::assertSame(401, $e->status);
        self::assertSame(['error' => 'missing_token'], $e->body);
    }

    public function testNonBearerHeaderDoesNotFallThroughToQuery(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), acceptQueryToken: true);
        self::assertSame(401, $this->catchAuth(fn () => $r->resolve('Basic xyz', 'querytok'))->status);
    }

    public function testInvalidToken401(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(401, ['error' => 'x'])));
        self::assertSame(401, $this->catchAuth(fn () => $r->resolve('Bearer tok'))->status);
    }

    public function testBackend403(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(403, ['error' => 'x'])));
        self::assertSame(403, $this->catchAuth(fn () => $r->resolve('Bearer tok'))->status);
    }

    public function testBackend5xx503(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(500, ['error' => 'x'])), retryAttempts: 1);
        self::assertSame(503, $this->catchAuth(fn () => $r->resolve('Bearer tok'))->status);
    }

    public function testRequireEmailVerifiedGate(): void
    {
        $t = FakeTransport::json(200, ['id' => 'u', 'email' => 'e@x.co', 'email_verified' => false]);
        $r = new Resolver(Fixtures::secretClient($t), requireEmailVerified: true);
        $e = $this->catchAuth(fn () => $r->resolve('Bearer tok'));
        self::assertSame(403, $e->status);
        self::assertSame('email_unverified', $e->body['reason']);
    }

    public function testRequirePlanGate(): void
    {
        $t = FakeTransport::json(200, ['id' => 'u', 'email' => 'e@x.co', 'plan' => 'free']);
        $r = new Resolver(Fixtures::secretClient($t), requirePlan: ['pro']);
        $e = $this->catchAuth(fn () => $r->resolve('Bearer tok'));
        self::assertSame(403, $e->status);
        self::assertSame('plan', $e->body['reason']);
    }

    public function testRequirePlanPasses(): void
    {
        $t = FakeTransport::json(200, ['id' => 'u', 'email' => 'e@x.co', 'plan' => 'pro']);
        $r = new Resolver(Fixtures::secretClient($t), requirePlan: ['pro', 'business']);
        self::assertSame('pro', $r->resolve('Bearer tok')->plan);
    }

    public function testCachesVerification(): void
    {
        $t = FakeTransport::json(200, Fixtures::OK_USER);
        $r = new Resolver(Fixtures::secretClient($t));
        $r->resolve('Bearer tok');
        $r->resolve('Bearer tok');
        self::assertSame(1, $t->calls); // second read is cached
    }

    public function testAcceptsQueryTokenWhenEnabled(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), acceptQueryToken: true);
        self::assertSame('user_1', $r->resolve(null, 'query-token')->id);
    }

    public function testIgnoresQueryTokenWhenDisabled(): void
    {
        $r = new Resolver(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)));
        self::assertSame(401, $this->catchAuth(fn () => $r->resolve(null, 'query-token'))->status);
    }

    public function testThrowingCallbackNeverBreaksTheRequest(): void
    {
        $r = new Resolver(
            Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)),
            onAuthEvent: fn (?string $id, string $e) => throw new \RuntimeException('boom'),
        );
        self::assertSame('user_1', $r->resolve('Bearer tok')->id);
    }

    public function testIdentifyMonitorBridgeAttachesUserToMonitorScope(): void
    {
        $fake = FakeTransport::json(200, Fixtures::OK_USER);
        $client = Fixtures::secretClient($fake);
        $client->monitor->init(['installGlobalHandlers' => false]);
        $client->monitor->flush(); // drain the auto-emitted `session_opened` baseline event
        $user = Resolver::fromOptions($client)->resolve('Bearer tok', null);
        self::assertSame('user_1', $user->id);
        // The bridge set the user on the monitor scope → the next event carries it.
        $client->monitor->captureMessage('probe');
        $client->monitor->flush();
        $payload = json_decode((string) $fake->lastBody, true);
        self::assertSame('user_1', $payload['events'][0]['userId'] ?? null);
        $client->monitor->close();
    }

    public function testIdentifyMonitorFalseDoesNotAttachUser(): void
    {
        $fake = FakeTransport::json(200, Fixtures::OK_USER);
        $client = Fixtures::secretClient($fake);
        $client->monitor->init(['installGlobalHandlers' => false]);
        $client->monitor->flush(); // drain the auto-emitted `session_opened` baseline event
        Resolver::fromOptions($client, ['identifyMonitor' => false])->resolve('Bearer tok', null);
        $client->monitor->captureMessage('probe');
        $client->monitor->flush();
        $payload = json_decode((string) $fake->lastBody, true);
        self::assertNull($payload['events'][0]['userId'] ?? null);
        $client->monitor->close();
    }

    public function testIdentifyMonitorSkippedOnPersistentRuntimeUntilScopeReset(): void
    {
        putenv('RR_MODE=http'); // simulate a persistent runtime (RoadRunner)
        try {
            $fake = FakeTransport::json(200, Fixtures::OK_USER);
            $client = Fixtures::secretClient($fake);
            $client->monitor->init(['installGlobalHandlers' => false]);
            $client->monitor->flush(); // drain the auto-emitted `session_opened` baseline event
            // Persistent runtime, monitor middleware NOT mounted (no resetScope) →
            // the bridge must skip so a user can't leak across requests.
            Resolver::fromOptions($client)->resolve('Bearer tok', null);
            $client->monitor->captureMessage('probe');
            $client->monitor->flush();
            self::assertNull(json_decode((string) $fake->lastBody, true)['events'][0]['userId'] ?? null);
            // Once the monitor middleware resets the scope, attribution resumes.
            $client->monitor->resetScope();
            Resolver::fromOptions($client)->resolve('Bearer tok2', null);
            $client->monitor->captureMessage('probe2');
            $client->monitor->flush();
            self::assertSame('user_1', json_decode((string) $fake->lastBody, true)['events'][0]['userId'] ?? null);
            $client->monitor->close();
        } finally {
            putenv('RR_MODE');
        }
    }
}
