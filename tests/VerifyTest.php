<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Onelo\Auth\OneloAuthError;
use Onelo\Auth\OneloAuthForbidden;
use Onelo\Auth\OneloAuthInvalidToken;
use Onelo\Auth\OneloAuthMissingToken;
use Onelo\Auth\OneloAuthRateLimited;
use Onelo\Auth\OneloAuthUnavailable;
use Onelo\Auth\Verify;
use Onelo\Tests\Support\FakeTransport;
use Onelo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class VerifyTest extends TestCase
{
    public function testExtractBearerToken(): void
    {
        self::assertSame('abc', Verify::extractBearerToken('Bearer abc'));
        self::assertSame('abc', Verify::extractBearerToken('bearer   abc  '));
        self::assertNull(Verify::extractBearerToken(null));
        self::assertNull(Verify::extractBearerToken(''));
        self::assertNull(Verify::extractBearerToken('Basic xyz'));
        self::assertNull(Verify::extractBearerToken('Bearer '));
    }

    public function testParseRetryAfter(): void
    {
        self::assertSame(30.0, Verify::parseRetryAfter('30'));
        self::assertSame(5.0, Verify::parseRetryAfter(' 5 '));
        self::assertSame(2.5, Verify::parseRetryAfter('2.5'));
        self::assertNull(Verify::parseRetryAfter(null));
        self::assertNull(Verify::parseRetryAfter('soon'));
        self::assertNull(Verify::parseRetryAfter('-1'));
        self::assertNull(Verify::parseRetryAfter('   '));
        self::assertNull(Verify::parseRetryAfter('0x1A'));
    }

    public function testHappyPath(): void
    {
        $user = Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), 'tok');
        self::assertSame('user_1', $user->id);
        self::assertSame('a@b.co', $user->email);
        self::assertSame('pro', $user->plan); // from metadata fallback
        self::assertFalse($user->emailVerified);
    }

    public function testTopLevelPlanAndVerified(): void
    {
        $t = FakeTransport::json(200, ['id' => 'u', 'email' => 'e@x.co', 'email_verified' => true, 'plan' => 'business']);
        $user = Verify::verifyToken(Fixtures::secretClient($t), 'tok');
        self::assertTrue($user->emailVerified);
        self::assertSame('business', $user->plan);
    }

    public function testEmptyTokenThrowsMissing(): void
    {
        $this->expectException(OneloAuthMissingToken::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(200, Fixtures::OK_USER)), '  ');
    }

    public function testPublishableKeyRejected(): void
    {
        $this->expectException(OneloAuthError::class);
        Verify::verifyToken(Fixtures::publishableClient(FakeTransport::json(200, Fixtures::OK_USER)), 'tok');
    }

    public function test401MapsToInvalid(): void
    {
        $this->expectException(OneloAuthInvalidToken::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(401, ['error' => 'x'])), 'tok');
    }

    public function test403MapsToForbidden(): void
    {
        $this->expectException(OneloAuthForbidden::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(403, ['error' => 'x'])), 'tok');
    }

    public function test429MapsToRateLimitedWithRetryAfter(): void
    {
        $t = FakeTransport::json(429, ['error' => 'x'], ['Retry-After' => '12']);
        try {
            Verify::verifyToken(Fixtures::secretClient($t), 'tok');
            self::fail('expected OneloAuthRateLimited');
        } catch (OneloAuthRateLimited $e) {
            self::assertInstanceOf(OneloAuthUnavailable::class, $e); // subclass relationship
            self::assertSame(12.0, $e->retryAfter);
        }
    }

    public function test5xxMapsToUnavailable(): void
    {
        $this->expectException(OneloAuthUnavailable::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(503, ['error' => 'x'])), 'tok');
    }

    public function testNonObject200IsUnavailable(): void
    {
        $this->expectException(OneloAuthUnavailable::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(200, [1, 2, 3])), 'tok');
    }

    public function testNoUserId200IsUnavailable(): void
    {
        $this->expectException(OneloAuthUnavailable::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::json(200, ['email' => 'e@x.co'])), 'tok');
    }

    public function testInvalidJson200IsUnavailable(): void
    {
        $this->expectException(OneloAuthUnavailable::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::raw(200, 'not json')), 'tok');
    }

    public function testNetworkErrorIsUnavailable(): void
    {
        $this->expectException(OneloAuthUnavailable::class);
        Verify::verifyToken(Fixtures::secretClient(FakeTransport::throwing()), 'tok');
    }

    public function testSendsBearerAndSecretHeaders(): void
    {
        $t = FakeTransport::json(200, Fixtures::OK_USER);
        Verify::verifyToken(Fixtures::secretClient($t), 'the-token');
        self::assertSame('https://api.test.onelo.tools/api/sdk/auth/user', $t->lastUrl);
        self::assertSame('Bearer the-token', $t->lastHeaders['Authorization']);
        self::assertSame('onelo_sk_test_abc123', $t->lastHeaders['X-Onelo-Secret-Key']);
        self::assertStringStartsWith('onelo-php/', $t->lastHeaders['User-Agent']);
    }

    public function testRetries5xxThenSucceeds(): void
    {
        $t = FakeTransport::sequence([[500, ['error' => 'x']], [200, Fixtures::OK_USER]]);
        $user = Verify::verifyWithRetry(Fixtures::secretClient($t), 'tok', 3, 1000);
        self::assertSame('user_1', $user->id);
        self::assertSame(2, $t->calls);
    }

    public function testDoesNotRetry429(): void
    {
        $t = FakeTransport::sequence([[429, ['error' => 'x'], ['Retry-After' => '5']], [200, Fixtures::OK_USER]]);
        try {
            Verify::verifyWithRetry(Fixtures::secretClient($t), 'tok', 3, 1000);
            self::fail('expected OneloAuthRateLimited');
        } catch (OneloAuthRateLimited) {
            // expected
        }
        self::assertSame(1, $t->calls);
    }

    public function testDoesNotRetry401(): void
    {
        $t = FakeTransport::sequence([[401, ['error' => 'x']], [200, Fixtures::OK_USER]]);
        try {
            Verify::verifyWithRetry(Fixtures::secretClient($t), 'tok');
            self::fail('expected OneloAuthInvalidToken');
        } catch (OneloAuthInvalidToken) {
            // expected
        }
        self::assertSame(1, $t->calls);
    }

    public function testGivesUpAfterAttempts(): void
    {
        $t = FakeTransport::sequence([[500, ['error' => 'x']]]);
        try {
            Verify::verifyWithRetry(Fixtures::secretClient($t), 'tok', 2, 1000);
            self::fail('expected OneloAuthUnavailable');
        } catch (OneloAuthUnavailable) {
            // expected
        }
        self::assertSame(2, $t->calls);
    }
}
