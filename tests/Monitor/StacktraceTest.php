<?php

declare(strict_types=1);

namespace Onelo\Tests\Monitor;

use Onelo\Monitor\CapturedException;
use Onelo\Monitor\Stacktrace;
use PHPUnit\Framework\TestCase;

final class StacktraceTest extends TestCase
{
    public function testCaptureBasics(): void
    {
        $e = new \RuntimeException('boom');
        $captured = Stacktrace::capture($e);
        self::assertSame('RuntimeException', $captured->type); // short class name
        self::assertSame('boom', $captured->message);
        self::assertNotEmpty($captured->frames);
    }

    public function testShortClassStripsNamespace(): void
    {
        $e = new \InvalidArgumentException('x');
        self::assertSame('InvalidArgumentException', Stacktrace::capture($e)->type);
    }

    public function testFramesHaveThrowSiteFirst(): void
    {
        $e = $this->makeError();
        $captured = Stacktrace::capture($e);
        $wire = $captured->toWire();
        self::assertArrayHasKey('filename', $wire['frames'][0]);
        // Throw site is inside this test file (developer code) → in_app true.
        self::assertTrue($wire['frames'][0]['in_app']);
        self::assertStringContainsString('StacktraceTest.php', $wire['frames'][0]['filename']);
    }

    public function testCauseChainCaptured(): void
    {
        $root = new \RuntimeException('root cause');
        $wrapper = new \LogicException('wrapper', 0, $root);
        $captured = Stacktrace::capture($wrapper);
        self::assertInstanceOf(CapturedException::class, $captured->cause);
        self::assertSame('RuntimeException', $captured->cause->type);
        self::assertSame('root cause', $captured->cause->message);
        self::assertArrayHasKey('cause', $captured->toWire());
    }

    public function testNoCauseOmitsCauseKey(): void
    {
        $captured = Stacktrace::capture(new \RuntimeException('solo'));
        self::assertNull($captured->cause);
        self::assertArrayNotHasKey('cause', $captured->toWire());
    }

    public function testCaptureCurrentStackReturnsFrames(): void
    {
        $frames = Stacktrace::captureCurrentStack(1);
        self::assertNotEmpty($frames);
        self::assertArrayHasKey('in_app', $frames[0]->toWire());
    }

    public function testEmptyTraceStillYieldsThrowSiteFrame(): void
    {
        // A top-level throw (CLI bootstrap, config check) has an EMPTY getTrace();
        // the throw-site file/line must not be lost. getTrace() is final on
        // Throwable and can't be stubbed, so exercise the mapping directly.
        $method = new \ReflectionMethod(Stacktrace::class, 'framesFrom');
        /** @var list<\Onelo\Monitor\StackFrame> $frames */
        $frames = $method->invoke(null, '/app/bootstrap.php', 12, []);
        self::assertCount(1, $frames);
        $wire = $frames[0]->toWire();
        self::assertSame('/app/bootstrap.php', $wire['filename']);
        self::assertSame(12, $wire['lineno']);
        self::assertTrue($wire['in_app']); // not under /vendor/
    }

    public function testEmptyTraceEmptyFileYieldsFrameWithNullLocation(): void
    {
        $method = new \ReflectionMethod(Stacktrace::class, 'framesFrom');
        $frames = $method->invoke(null, '', 0, []);
        self::assertCount(1, $frames);
        $wire = $frames[0]->toWire();
        self::assertArrayNotHasKey('filename', $wire);
        self::assertArrayNotHasKey('lineno', $wire);
        self::assertFalse($wire['in_app']);
    }

    public function testRealExceptionAlwaysHasAtLeastOneFrame(): void
    {
        // End-to-end guarantee: no matter how shallow, capture() yields ≥1 frame.
        self::assertNotEmpty(Stacktrace::capture(new \RuntimeException('x'))->frames);
    }

    private function makeError(): \Throwable
    {
        return new \RuntimeException('nested');
    }

    public function testCaptureReadsSourceContextLineForInAppFrames(): void
    {
        $captured = Stacktrace::capture(new \RuntimeException('boom')); // CONTEXT_PROBE_MARKER
        $top = $captured->toWire()['frames'][0];
        // The throw-site frame is this test file (in-app, not /vendor/) → source read.
        self::assertTrue($top['in_app']);
        self::assertIsString($top['context_line'] ?? null);
        self::assertStringContainsString('CONTEXT_PROBE_MARKER', $top['context_line']);
    }
}
