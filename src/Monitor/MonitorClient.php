<?php

declare(strict_types=1);

namespace Onelo\Monitor;

use Onelo\Onelo;
use Onelo\Version;

/**
 * `$onelo->monitor` — server-side error / event tracking. 1:1 with Python/Node's
 * monitor pipeline, adapted to PHP's runtime:
 *   - NO background thread → events buffer in memory and flush SYNCHRONOUSLY at
 *     request end via `register_shutdown_function` (or an explicit flush()).
 *   - NO contextvars/ALS → one instance-level {@see Scope} IS request-isolated
 *     under PHP-FPM (one process per request). Persistent runtimes (Octane/Swoole)
 *     call resetScope() per request.
 *   - Global capture via `set_exception_handler` + a fatal-error shutdown check.
 */
final class MonitorClient
{
    private const SDK_NAME = 'onelo-php';

    private ?MonitorTransport $transport = null;
    private Scope $scope;
    private ?string $sessionId = null;
    private ?string $deployRelease = null;
    private ?string $deployEnvironment = null;
    private ?string $deployServerName = null;
    private float $errorSampleRate = 1.0;
    private float $successSampleRate = 1.0;
    /** @var callable(): array<string, string> */
    private $flagProvider;
    private bool $shutdownRegistered = false;
    private bool $globalHandlersInstalled = false;
    /** @var callable|null */
    private $previousExceptionHandler = null;
    private bool $errorHandlerInstalled = false;
    /** @var callable|null */
    private $previousErrorHandler = null;
    private bool $inErrorHandler = false;
    private bool $scopeResetThisRequest = false;
    private bool $persistentRuntimeWarned = false;

    /** @param (callable(): array<string, string>)|null $flagProvider */
    public function __construct(private readonly Onelo $client, ?callable $flagProvider = null)
    {
        $this->scope = new Scope();
        $this->flagProvider = $flagProvider ?? static fn (): array => [];
    }

    /**
     * Override the feature-flag snapshot source stamped onto every event's
     * `meta.flags`. `$onelo->monitor` is already auto-wired to the Features
     * cache; call this only on a standalone monitor build (no Features) or to
     * inject a custom/test provider. Passing null restores the empty default.
     * Parity with Python's `monitor.set_flag_provider`.
     *
     * @param (callable(): array<string, string>)|null $provider
     */
    public function setFlagProvider(?callable $provider): void
    {
        $this->flagProvider = $provider ?? static fn (): array => [];
    }

    // ── lifecycle ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $options */
    public function init(array $options = []): void
    {
        $sampleRate = (float) ($options['sampleRate'] ?? 1.0);
        $successSampleRate = (float) ($options['successSampleRate'] ?? 1.0);
        foreach ([['sampleRate', $sampleRate], ['successSampleRate', $successSampleRate]] as [$name, $rate]) {
            if (!($rate >= 0 && $rate <= 1)) {
                throw new \InvalidArgumentException("$name must be between 0 and 1, got $rate");
            }
        }

        if ($this->transport !== null) {
            error_log('[onelo.monitor] init called twice; closing previous transport');
            $this->close();
        }

        $this->errorSampleRate = $sampleRate;
        $this->successSampleRate = $successSampleRate;
        $this->deployRelease = self::firstNonEmpty($options['release'] ?? null, getenv('ONELO_RELEASE'), getenv('GIT_COMMIT_SHA'));
        // ENVIRONMENT matches the Python SDK's fallback; APP_ENV is Laravel/Symfony's
        // convention (the PHP analogue of NODE_ENV). Explicit option wins, then the
        // Onelo-specific var, then the generic name, then the framework one.
        $this->deployEnvironment = self::firstNonEmpty($options['environment'] ?? null, getenv('ONELO_ENVIRONMENT'), getenv('ENVIRONMENT'), getenv('APP_ENV'));
        // Hostname / pod id — groups a worker's events in the dashboard. Same
        // option + HOSTNAME/POD_NAME fallback as Python (_init.py) and Node
        // (monitor/client.ts), landing on meta.tags.server_name.
        $this->deployServerName = self::firstNonEmpty($options['serverName'] ?? null, getenv('HOSTNAME'), getenv('POD_NAME'));

        Scrub::setStrictEmailScrub((bool) ($options['strictEmailScrub'] ?? false));
        Scrub::configureSensitiveKeys($options['sensitiveHeaders'] ?? null);

        $this->transport = new MonitorTransport(
            apiUrl: $this->client->apiUrl,
            key: $this->client->key,
            transport: $this->client->transport,
            requestTimeoutMs: $this->client->requestTimeoutMs,
            // Clamp to ≥1: a 0/negative buffer would make add() evict-then-append
            // on every call (perpetual 1-event buffer + runaway drop counter).
            bufferSize: max(1, (int) ($options['bufferSize'] ?? 200)),
        );
        $this->sessionId = bin2hex(random_bytes(16));

        // Register the shutdown drain ONCE (delivers the buffer at request end).
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'onShutdown']);
            $this->shutdownRegistered = true;
        }

        if (($options['installGlobalHandlers'] ?? true) === true) {
            $this->previousExceptionHandler = set_exception_handler([$this, 'onUncaughtException']);
            $this->globalHandlersInstalled = true;
        }

        // Opt-in: capture PHP engine errors (warnings/notices/deprecations) as
        // breadcrumbs — the trail leading up to an eventual event. Off by default
        // because it fires on EVERY notice/warning (parity with Node captureConsole).
        // Real errors still become events via the exception / fatal-shutdown path.
        if (($options['captureErrors'] ?? false) === true) {
            $this->previousErrorHandler = set_error_handler([$this, 'onError']);
            $this->errorHandlerInstalled = true;
        }
    }

    public function isInitialised(): bool
    {
        return $this->transport !== null;
    }

    /** Drain the buffer now (end of a CLI command / queue job). */
    public function flush(): void
    {
        $this->transport?->flush();
    }

    public function close(): void
    {
        if ($this->globalHandlersInstalled) {
            restore_exception_handler();
            $this->globalHandlersInstalled = false;
            $this->previousExceptionHandler = null;
        }
        if ($this->errorHandlerInstalled) {
            restore_error_handler();
            $this->errorHandlerInstalled = false;
            $this->previousErrorHandler = null;
        }
        // Reset ALL static scrub config symmetrically with init() — strict-email
        // AND custom sensitive keys — so a closed client leaves no policy behind
        // in a persistent process (Octane/Swoole/CLI).
        Scrub::setStrictEmailScrub(false);
        Scrub::configureSensitiveKeys(null);
        $transport = $this->transport;
        $this->transport = null;
        $this->sessionId = null;
        $this->deployRelease = null;
        $this->deployEnvironment = null;
        $this->deployServerName = null;
        $this->errorSampleRate = 1.0;
        $this->successSampleRate = 1.0;
        $transport?->flush(true);
    }

    /** Clear per-request scope — call at the start of each request under Octane/Swoole. */
    public function resetScope(): void
    {
        $this->scope->reset();
        $this->scopeResetThisRequest = true;
    }

    /**
     * True when it's safe to write per-request scope data (used by the
     * auth→monitor bridge): under PHP-FPM (process-per-request isolation) OR once
     * the monitor middleware has {@see resetScope}'d this request. On a persistent
     * runtime (Octane/Swoole/RoadRunner) WITHOUT the monitor middleware it returns
     * false — and warns once — so a verified user can never leak across requests.
     */
    public function isRequestScopeIsolated(): bool
    {
        if ($this->scopeResetThisRequest || !self::isPersistentRuntime()) {
            return true;
        }
        if (!$this->persistentRuntimeWarned) {
            $this->persistentRuntimeWarned = true;
            error_log(
                '[onelo.monitor] persistent runtime (Octane/Swoole/RoadRunner) detected without the '
                . 'monitor middleware — mount Onelo\\Laravel\\OneloMonitor or '
                . 'Onelo\\Psr15\\OneloMonitorMiddleware so per-request scope (user/breadcrumbs) is '
                . 'isolated. Auto user attribution is disabled here to avoid cross-request leaks.'
            );
        }

        return false;
    }

    private static function isPersistentRuntime(): bool
    {
        return extension_loaded('swoole')
            || extension_loaded('openswoole')
            || getenv('LARAVEL_OCTANE') !== false
            || getenv('RR_MODE') !== false;
    }

    // ── capture API ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $meta */
    public function captureException(\Throwable $e, string $featureName = 'manual', array $meta = []): void
    {
        try {
            $captured = Stacktrace::capture($e);
            $meta['exception'] = $captured->toWire();
            $meta['error_type'] ??= $captured->type;
            $this->emit(MonitorEvent::make($featureName, false, error: $e->getMessage(), source: 'event', meta: $meta));
        } catch (\Throwable $inner) {
            // Never let capture break the caller — even stack-walking faults.
            error_log('[onelo.monitor] captureException failed: ' . $inner->getMessage());
        }
    }

    /** @param array<string, mixed> $meta */
    public function captureMessage(string $message, string $level = 'info', string $featureName = 'manual', bool $attachStacktrace = false, array $meta = []): void
    {
        $meta['level'] = $level;
        if ($attachStacktrace) {
            $meta['stack'] = array_map(static fn (StackFrame $f): array => $f->toWire(), Stacktrace::captureCurrentStack(1));
        }
        $isError = $level === 'error' || $level === 'fatal';
        $this->emit(MonitorEvent::make($featureName, !$isError, error: $isError ? $message : null, source: 'event', meta: $meta));
    }

    /**
     * Measure a callable and emit one source="track" event (ok=true on success,
     * an error event with full stack on throw), then re-throw so control flow is
     * unchanged. Returns the callable's result.
     *
     * @template T
     *
     * @param callable(): T        $fn
     * @param array<string, mixed> $meta
     *
     * @return T
     */
    public function track(string $featureName, callable $fn, array $meta = []): mixed
    {
        $start = hrtime(true);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            // Record the failure, then ALWAYS re-throw the caller's exception —
            // SDK bookkeeping (stack capture / emit) must never mask or replace it.
            try {
                $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
                $captured = Stacktrace::capture($e);
                $meta['exception'] = $captured->toWire();
                $meta['error_type'] ??= $captured->type;
                $this->emit(MonitorEvent::make($featureName, false, error: $e->getMessage(), source: 'track', durationMs: $durationMs, meta: $meta));
            } catch (\Throwable $inner) {
                error_log('[onelo.monitor] track capture failed: ' . $inner->getMessage());
            }

            throw $e;
        }
        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
        $this->emit(MonitorEvent::make($featureName, true, source: 'track', durationMs: $durationMs, meta: $meta));

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function addBreadcrumb(Breadcrumb|string $crumbOrMessage, string $category = 'info', string $level = 'info', array $data = []): void
    {
        if ($crumbOrMessage instanceof Breadcrumb) {
            $this->scope->addBreadcrumb($crumbOrMessage);

            return;
        }
        $this->scope->addBreadcrumb(new Breadcrumb($category, $crumbOrMessage, microtime(true), $level, $data !== [] ? $data : null));
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->scope->setUser($user);
    }

    public function setTag(string $key, string $value): void
    {
        $this->scope->setTag($key, $value);
    }

    /** @param array<string, mixed> $value */
    public function setContext(string $key, array $value): void
    {
        $this->scope->setContext($key, $value);
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->scope->setExtra($key, $value);
    }

    // ── cross-process scope propagation (queue jobs) ─────────────────────────

    /**
     * Serialise the active scope (user.id + scrubbed tags/contexts, optionally
     * breadcrumbs) into a JSON-safe carrier you attach to a queued job, so the
     * worker can restore it via {@see continueTrace}. Pass `$key` (your
     * publishable key) to HMAC-sign it — the worker verifies before trusting.
     * Parity with Python's `monitor.carrier()`.
     *
     * Producer (dispatch side):
     *   MyJob::dispatch($payload, $onelo->monitor->carrier(key: $onelo->key));
     *
     * @return array<string, mixed>
     */
    public function carrier(bool $includeBreadcrumbs = false, ?string $key = null): array
    {
        return Propagation::build($this->scope, $includeBreadcrumbs, $key);
    }

    /**
     * Restore a carrier onto a FRESH scope for the duration of `$fn` (the queue
     * job body), then restore the previously-active scope. A forged, tampered, or
     * signed-but-key-missing carrier is rejected — the job still runs, just with
     * an empty scope, so a single bad message never crashes the worker. Returns
     * whatever `$fn` returns. Parity with Python's `monitor.continue_trace()`.
     *
     * Worker (job handle side):
     *   public function handle(): void {
     *       $this->onelo->monitor->continueTrace($this->carrier, function () {
     *           // ... work; captured errors carry the dispatching request's user/tags
     *       }, key: $this->onelo->key);
     *   }
     *
     * @template T
     *
     * @param array<string, mixed>|null $carrier
     * @param callable(): T             $fn
     *
     * @return T
     */
    public function continueTrace(?array $carrier, callable $fn, ?string $key = null): mixed
    {
        $payload = Propagation::unwrap($carrier, $key);
        $saved = $this->scope;
        $this->scope = new Scope();
        if ($payload !== null) {
            Propagation::apply($this->scope, $payload);
        }
        try {
            return $fn();
        } finally {
            $this->scope = $saved;
        }
    }

    // ── shutdown / global handlers ───────────────────────────────────────────

    /** @internal registered via register_shutdown_function */
    public function onShutdown(): void
    {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $this->captureMessage(
                (string) $err['message'],
                'fatal',
                'fatal',
                meta: ['fatal' => true, 'file' => $err['file'] ?? null, 'line' => $err['line'] ?? null],
            );
        }
        $this->transport?->flush(true);
    }

    /** @internal registered via set_exception_handler */
    public function onUncaughtException(\Throwable $e): void
    {
        $this->captureException($e, 'uncaught');
        $this->transport?->flush(true);
        // Preserve prior behavior: chain to the previous handler, else log it so
        // the uncaught error is still visible (never silently swallowed).
        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($e);
        } else {
            error_log((string) $e);
        }
    }

    /**
     * @internal registered via set_error_handler (opt-in captureErrors). Records
     * PHP engine errors as breadcrumbs (context for the next event), then chains
     * to the previous handler so the standard behaviour is preserved.
     */
    public function onError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // Re-entrancy guard + honour @-suppression / the error_reporting level.
        if (!$this->inErrorHandler && (error_reporting() & $errno) !== 0) {
            $this->inErrorHandler = true;
            try {
                $isWarning = in_array($errno, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true);
                $this->addBreadcrumb(
                    $errstr,
                    category: $isWarning ? 'error' : 'log',
                    level: $isWarning ? 'warning' : 'info',
                    data: ['errno' => $errno, 'file' => $errfile, 'line' => $errline],
                );
            } catch (\Throwable $inner) {
                error_log('[onelo.monitor] onError failed: ' . $inner->getMessage());
            } finally {
                $this->inErrorHandler = false;
            }
        }

        // Chain to the previous handler (framework error→exception conversion, etc.),
        // else return false so PHP's standard error handling still runs.
        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function shouldKeep(MonitorEvent $event): bool
    {
        $rate = $event->ok ? $this->successSampleRate : $this->errorSampleRate;
        if ($rate >= 1) {
            return true;
        }
        if ($rate <= 0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function emit(MonitorEvent $event): void
    {
        $transport = $this->transport;
        if ($transport === null) {
            return; // not initialised — drop
        }
        if (!$this->shouldKeep($event)) {
            return; // client-side sampling before any work
        }

        // Capture must NEVER break the caller's request. Everything from scope
        // merge through transport-buffering is wrapped so an unexpected fault in
        // enrichment (or a future edit) drops the event instead of propagating.
        try {
            $this->scope->applyTo($event);

            if ($event->sessionId === null && $this->sessionId !== null) {
                $event->sessionId = $this->sessionId;
            }

            // Deploy context on the EXACT meta paths the backend aggregates on.
            if ($this->deployEnvironment !== null && !array_key_exists('environment', $event->meta)) {
                $event->meta['environment'] = $this->deployEnvironment;
            }
            if ($this->deployRelease !== null) {
                $app = $event->meta['app'] ?? [];
                if (is_array($app) && !array_key_exists('version', $app)) {
                    $app['version'] = $this->deployRelease;
                    $event->meta['app'] = $app;
                }
            }
            // server_name lands in meta.tags.* (like Python/Node's global-scope
            // tag). Stamped here from the deploy field — NOT as a scope tag — so it
            // survives resetScope() under a persistent runtime (Octane/Swoole).
            if ($this->deployServerName !== null) {
                $tags = $event->meta['tags'] ?? [];
                if (is_array($tags) && !array_key_exists('server_name', $tags)) {
                    $tags['server_name'] = $this->deployServerName;
                    $event->meta['tags'] = $tags;
                }
            }

            // Feature-flag correlation (empty in an auth+monitor-only build).
            try {
                $flags = ($this->flagProvider)();
                if (is_array($flags) && $flags !== [] && !array_key_exists('flags', $event->meta)) {
                    $event->meta['flags'] = $flags;
                }
            } catch (\Throwable) {
                // flag provider must never break capture
            }

            if (!array_key_exists('sdk', $event->meta)) {
                $event->meta['sdk'] = ['name' => self::SDK_NAME, 'version' => Version::VERSION];
            }

            // Scrub at the boundary.
            $event->error = Scrub::scrubText($event->error);
            $event->meta = Scrub::scrubMeta($event->meta);

            $transport->add($event);
        } catch (\Throwable $e) {
            error_log('[onelo.monitor] emit failed; dropping event: ' . $e->getMessage());
        }
    }

    private static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
