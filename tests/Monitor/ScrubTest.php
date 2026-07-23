<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\Scrub;
use PHPUnit\Framework\TestCase;

final class ScrubTest extends TestCase
{
    protected function tearDown(): void
    {
        // Static config leaks across tests — reset to defaults.
        Scrub::setStrictEmailScrub(false);
        Scrub::configureSensitiveKeys(null);
    }

    public function testScrubTextRedactsSecrets(): void
    {
        self::assertSame('[REDACTED]', Scrub::scrubText('Bearer abc.def_ghi'));
        self::assertSame('[REDACTED]', Scrub::scrubText('eyJhbGc.eyJzdWI.sIgNaTuRe'));
        self::assertSame('[REDACTED]', Scrub::scrubText('sk_live_' . str_repeat('a', 20)));
        self::assertSame('[REDACTED]', Scrub::scrubText('onelo_sk_live_' . str_repeat('a', 12)));
        self::assertSame('[REDACTED]', Scrub::scrubText('4111 1111 1111 1111'));
        self::assertSame('[REDACTED]', Scrub::scrubText('4111111111111111'));
    }

    public function testScrubTextLeavesBenignTextAndBoundaries(): void
    {
        self::assertSame('hello world', Scrub::scrubText('hello world'));
        self::assertNull(Scrub::scrubText(null));
        self::assertSame('', Scrub::scrubText(''));
        // Env-var concatenation still latches (underscore is not a boundary char).
        self::assertStringContainsString('[REDACTED]', Scrub::scrubText('API_sk_live_' . str_repeat('a', 20)));
    }

    public function testEmailOnlyScrubbedInStrictMode(): void
    {
        self::assertSame('reach me at joe@example.com', Scrub::scrubText('reach me at joe@example.com'));
        Scrub::setStrictEmailScrub(true);
        self::assertSame('reach me at [REDACTED]', Scrub::scrubText('reach me at joe@example.com'));
    }

    public function testIsPiiKey(): void
    {
        self::assertTrue(Scrub::isPiiKey('password'));
        self::assertTrue(Scrub::isPiiKey('Authorization'));
        self::assertTrue(Scrub::isPiiKey('api_key'));
        self::assertTrue(Scrub::isPiiKey('x-anthropic-key'));  // segment "key"
        self::assertFalse(Scrub::isPiiKey('monkey'));           // substring "key" but not a segment
        self::assertFalse(Scrub::isPiiKey('username'));
    }

    public function testConfigureSensitiveKeys(): void
    {
        self::assertFalse(Scrub::isPiiKey('x-groq-signature'));
        Scrub::configureSensitiveKeys(['x-groq-signature']);
        self::assertTrue(Scrub::isPiiKey('X-Groq-Signature')); // case + dash normalised
    }

    public function testScrubUrlRedactsSensitiveQueryParams(): void
    {
        $out = Scrub::scrubUrl('https://api.x.co/v1/run?token=abc123&user=42&api_key=zzz');
        self::assertStringContainsString('token=%5BREDACTED%5D', $out);
        self::assertStringContainsString('api_key=%5BREDACTED%5D', $out);
        self::assertStringContainsString('user=42', $out);
        self::assertStringStartsWith('https://api.x.co/v1/run?', $out);
    }

    public function testScrubUrlKeepsBenignUrl(): void
    {
        self::assertSame('https://api.x.co/v1/run', Scrub::scrubUrl('https://api.x.co/v1/run'));
        self::assertNull(Scrub::scrubUrl(null));
    }

    public function testScrubUrlHandlesArrayStyleQueryParamsWithoutWarning(): void
    {
        // a[]=1&a[]=2 → parse_str yields ['a' => ['1','2']]; must not emit
        // "Array to string conversion" or corrupt the param to literal "Array".
        $out = Scrub::scrubUrl('https://api.x.co/run?a[]=1&a[]=2&token=sekret');
        self::assertStringNotContainsString('=Array', $out);
        self::assertStringContainsString('token=%5BREDACTED%5D', $out);
        self::assertStringContainsString(rawurlencode('["1","2"]'), $out); // JSON-encoded, not lost
    }

    public function testScrubMetaRedactsPiiKeysAndValues(): void
    {
        $out = Scrub::scrubMeta([
            'password' => 'hunter2',
            'nested' => ['token' => 'x', 'ok' => 'keep'],
            'note' => 'Bearer topsecret_value',
        ]);
        self::assertSame('[REDACTED]', $out['password']);
        self::assertSame('[REDACTED]', $out['nested']['token']);
        self::assertSame('keep', $out['nested']['ok']);
        self::assertSame('[REDACTED]', $out['note']);
        self::assertContains('password', $out['_onelo_redacted']);
        self::assertContains('token', $out['_onelo_redacted']);
    }

    public function testScrubMetaNoRedactionOmitsMarker(): void
    {
        $out = Scrub::scrubMeta(['a' => 1, 'b' => 'clean']);
        self::assertArrayNotHasKey('_onelo_redacted', $out);
    }

    public function testScrubMetaDepthCap(): void
    {
        // Build 12 levels deep — beyond MAX_DEPTH (10).
        $deep = 'bottom';
        for ($i = 0; $i < 12; $i++) {
            $deep = ['child' => $deep];
        }
        $out = Scrub::scrubMeta($deep);
        // Doesn't throw; marks depth-exceeded somewhere down the chain.
        self::assertContains('_onelo_depth_exceeded', $out['_onelo_redacted']);
    }

    public function testScrubHeadersRedactsSensitiveHeaders(): void
    {
        $out = Scrub::scrubHeaders([
            'Authorization' => 'Bearer secret',
            'Cookie' => 'session=abc',
            'X-Api-Key' => 'k123',
            'X-Request-Id' => 'req-42',
            'Content-Type' => 'application/json',
            'X-Multi' => ['a', 'b'],
        ]);
        self::assertSame(Scrub::REDACTED, $out['Authorization']); // full-header denylist
        self::assertSame(Scrub::REDACTED, $out['Cookie']);
        self::assertSame(Scrub::REDACTED, $out['X-Api-Key']);     // PII key (api_key)
        self::assertSame('req-42', $out['X-Request-Id']);          // not sensitive
        self::assertSame('application/json', $out['Content-Type']);
        self::assertSame('a, b', $out['X-Multi']);                 // multi-value joined
    }
}
