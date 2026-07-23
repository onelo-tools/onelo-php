<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * Flatten a \Throwable (and its getPrevious() cause chain) into a
 * CapturedException. PHP analogue of Python's `_stacktrace`. Uses PHP's standard
 * trace-to-frame mapping (each frame's file/line is where the NEXT-inner call was
 * made), so frame 0 is the throw site.
 */
final class Stacktrace
{
    private const SOURCE_CACHE_MAX = 200;
    private const MAX_CONTEXT_LINE_LEN = 512;

    /** @var array<string, list<string>|null> linecache-style source cache (filename → lines | null). */
    private static array $sourceCache = [];

    public static function capture(\Throwable $e, int $depth = 0): CapturedException
    {
        // Exception frames read one source line for in-app frames (context_line).
        $frames = self::framesFrom($e->getFile(), $e->getLine(), $e->getTrace(), true);

        $cause = null;
        $prev = $e->getPrevious();
        if ($prev !== null && $depth < 10) {
            $cause = self::capture($prev, $depth + 1);
        }

        return new CapturedException(
            type: self::shortClass(get_class($e)),
            message: $e->getMessage(),
            frames: $frames,
            cause: $cause,
        );
    }

    /**
     * Map a throw site + PHP trace array into ordered stack frames.
     *
     * @param list<array<string, mixed>> $trace $e->getTrace()
     *
     * @return list<StackFrame>
     *
     * @internal split out (and given primitive params) so the empty-trace
     *           synthesis path is unit-testable — getTrace()/getFile() are
     *           `final` on Throwable and can't be stubbed.
     */
    private static function framesFrom(string $throwFile, int $throwLine, array $trace, bool $includeContext = false): array
    {
        $frames = [];
        // The throw site pairs with the innermost trace entry's function.
        $file = $throwFile;
        $line = $throwLine;
        foreach ($trace as $t) {
            $class = $t['class'] ?? null;
            $function = $t['function'] ?? null;
            $qualified = null;
            if ($function !== null) {
                $qualified = $class !== null ? $class . ($t['type'] ?? '::') . $function : $function;
            }
            $inApp = self::isInApp($file);
            $frames[] = new StackFrame(
                function: $qualified,
                class: $class,
                filename: $file !== '' ? $file : null,
                lineno: $line > 0 ? $line : null,
                inApp: $inApp,
                contextLine: ($includeContext && $inApp && $file !== '' && $line > 0)
                    ? self::readSourceLine($file, $line)
                    : null,
            );
            $file = $t['file'] ?? '';
            $line = $t['line'] ?? 0;
        }

        // PHP's getTrace() OMITS the throw frame itself and is EMPTY when the
        // exception is thrown at the top level (CLI bootstrap, a config check
        // with no function on the stack). Synthesize the throw-site frame from
        // getFile()/getLine() so the location is never lost — matching Python,
        // whose traceback always includes the raise point (≥1 frame).
        if ($frames === []) {
            $inApp = self::isInApp($throwFile);
            $frames[] = new StackFrame(
                function: null,
                class: null,
                filename: $throwFile !== '' ? $throwFile : null,
                lineno: $throwLine > 0 ? $throwLine : null,
                inApp: $inApp,
                contextLine: ($includeContext && $inApp && $throwFile !== '' && $throwLine > 0)
                    ? self::readSourceLine($throwFile, $throwLine)
                    : null,
            );
        }

        return $frames;
    }

    /** Current call stack (no exception) — for captureMessage attachStacktrace. */
    public static function captureCurrentStack(int $skip = 1): array
    {
        $frames = [];
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // Drop the SDK's own frames from the top.
        $trace = array_slice($trace, $skip);
        foreach ($trace as $t) {
            $class = $t['class'] ?? null;
            $function = $t['function'] ?? null;
            $qualified = $function !== null
                ? ($class !== null ? $class . ($t['type'] ?? '::') . $function : $function)
                : null;
            $file = $t['file'] ?? '';
            $frames[] = new StackFrame(
                function: $qualified,
                class: $class,
                filename: $file !== '' ? $file : null,
                lineno: isset($t['line']) && $t['line'] > 0 ? $t['line'] : null,
                inApp: self::isInApp($file),
            );
        }

        return $frames;
    }

    /** In-app = the developer's own code (not a Composer dependency, not internal). */
    private static function isInApp(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }

        return !str_contains($file, '/vendor/') && !str_contains($file, '\\vendor\\');
    }

    /**
     * Read + cache one stripped source line (1-indexed). Best-effort — null on any
     * miss (bundled/unreadable file, out-of-range line). Bounded cache (drop-oldest
     * at SOURCE_CACHE_MAX). Parity with Python's linecache-based context_line.
     */
    private static function readSourceLine(string $file, int $lineno): ?string
    {
        if ($lineno < 1) {
            return null;
        }
        if (!array_key_exists($file, self::$sourceCache)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (count(self::$sourceCache) >= self::SOURCE_CACHE_MAX) {
                array_shift(self::$sourceCache); // drop oldest
            }
            self::$sourceCache[$file] = $lines === false ? null : $lines;
        }
        $lines = self::$sourceCache[$file];
        if ($lines === null || !isset($lines[$lineno - 1])) {
            return null;
        }
        $trimmed = trim($lines[$lineno - 1]);
        if ($trimmed === '') {
            return null;
        }

        return strlen($trimmed) > self::MAX_CONTEXT_LINE_LEN
            ? substr($trimmed, 0, self::MAX_CONTEXT_LINE_LEN) . '…'
            : $trimmed;
    }

    /** Short class name (matches Python's `type(exc).__name__` for cross-platform consistency). */
    private static function shortClass(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
