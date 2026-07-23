<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\RequestContext;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public function testBuildUppercasesMethodScrubsUrlAndDropsHeaders(): void
    {
        $ctx = RequestContext::build('post', 'http://x/pay?token=secret', [
            'Authorization' => 'Bearer s',
            'Cookie' => 'c=1',
            'X-Request-Id' => 'req-1',
            'Content-Type' => 'application/json',
        ]);
        self::assertSame('POST', $ctx['method']);
        self::assertStringContainsString('/pay', $ctx['url']);
        self::assertStringNotContainsString('secret', $ctx['url']); // sensitive query scrubbed
        // Drop-list headers (authorization/cookie) are removed entirely.
        self::assertArrayNotHasKey('authorization', $ctx['headers']);
        self::assertArrayNotHasKey('cookie', $ctx['headers']);
        // Non-sensitive headers survive (lowercased key).
        self::assertSame('req-1', $ctx['headers']['x-request-id']);
        self::assertSame('application/json', $ctx['headers']['content-type']);
    }

    public function testBuildOmitsEmptyUrlAndHeaders(): void
    {
        self::assertSame(['method' => 'GET'], RequestContext::build('GET', null, []));
    }
}
