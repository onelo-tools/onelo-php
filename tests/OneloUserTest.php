<?php

declare(strict_types=1);

namespace Onelo\Tests;

use Onelo\Auth\OneloUser;
use PHPUnit\Framework\TestCase;

final class OneloUserTest extends TestCase
{
    public function testFullPayload(): void
    {
        $u = OneloUser::fromPayload([
            'id' => 'u1',
            'email' => 'a@b.co',
            'email_verified' => true,
            'plan' => 'pro',
            'metadata' => ['x' => 1],
        ]);
        self::assertSame('u1', $u->id);
        self::assertSame('a@b.co', $u->email);
        self::assertTrue($u->emailVerified);
        self::assertSame('pro', $u->plan);
        self::assertSame(['x' => 1], $u->metadata);
    }

    public function testFallbacksFromMetadata(): void
    {
        $u = OneloUser::fromPayload(['id' => 'u', 'email' => 'e@x.co', 'metadata' => ['email_verified' => true, 'plan' => 'biz']]);
        self::assertTrue($u->emailVerified);
        self::assertSame('biz', $u->plan);
    }

    public function testDefaults(): void
    {
        $u = OneloUser::fromPayload(['id' => 'u', 'email' => 'e@x.co']);
        self::assertFalse($u->emailVerified);
        self::assertNull($u->plan);
        self::assertSame([], $u->metadata);
    }

    public function testNonObjectMetadataBecomesEmpty(): void
    {
        $u = OneloUser::fromPayload(['id' => 'u', 'email' => 'e@x.co', 'metadata' => ['list', 'not', 'object']]);
        self::assertSame([], $u->metadata);
    }

    public function testMissingIdCoercesToEmpty(): void
    {
        $u = OneloUser::fromPayload(['email' => 'e@x.co']);
        self::assertSame('', $u->id);
    }

    public function testStringZeroIdSurvives(): void
    {
        // Python truthiness: "0" is a non-empty string → kept (not '', unlike PHP empty()).
        $u = OneloUser::fromPayload(['id' => '0', 'email' => '0']);
        self::assertSame('0', $u->id);
        self::assertSame('0', $u->email);
    }

    public function testRawPreservesPayload(): void
    {
        $payload = ['id' => 'u', 'email' => 'e@x.co', 'extra' => 'kept'];
        $u = OneloUser::fromPayload($payload);
        self::assertSame('kept', $u->raw['extra']);
    }
}
