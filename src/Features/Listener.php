<?php

declare(strict_types=1);

namespace Onelo\Features;

use Onelo\Onelo;

/**
 * The Features streaming engine — the long-lived listener side. PHP's analogue of
 * Python's `onelo._backend.BackendThread`, minus the thread: because PHP-FPM is
 * request-per-process with no shared memory, there is no background thread to
 * spawn. The Listener IS the long-lived process — you run it as:
 *   - a supervised CLI daemon (systemd / supervisor / `php artisan onelo:features:listen`), or
 *   - a Laravel Octane / Swoole task inside a long-lived worker.
 *
 * It resolves the strategy, holds the SSE (default) or polling connection, and
 * writes the snapshot into a SHARED {@see SnapshotStore} — a {@see FileStore}
 * (separate daemon + FPM web workers, the common deployment) or an
 * {@see ArrayStore} (same process under Octane/Swoole). The PHP-FPM web workers
 * then READ that snapshot every request (via `$onelo->features`), exactly as
 * Python's request handlers read a snapshot maintained by the background thread.
 *
 * Do NOT run this inside a normal PHP-FPM web request — it blocks forever. If no
 * listener runs at all, the Features client's per-request `autoRefresh` poll is
 * the fallback; the listener is the always-fresh, streamed upgrade.
 *
 * On SIGTERM/SIGINT (when ext-pcntl is available) it stops the consumer gracefully
 * so a supervisor restart doesn't sever the stream mid-write.
 */
final class Listener
{
    private readonly SseConsumer|PollingConsumer $consumer;
    private readonly string $strategy;

    /**
     * @param Onelo         $client               source of apiUrl / key / featureEnvironment /
     *                                             requestTimeoutMs (the transport itself is NOT
     *                                             used — the consumers hold raw curl streams).
     * @param SnapshotStore $store                REQUIRED shared store the consumer writes and the
     *                                             web workers read (FileStore, or ArrayStore under Octane).
     * @param string        $strategy             'auto' | 'sse' | 'polling' (resolved via StrategyResolver).
     * @param float         $pollInterval         polling strategy interval, seconds (default 30).
     * @param string|null   $appVersion           optional app version reported to the backend (SSE only).
     * @param callable|null $onDiscoveryRequested invoked on a dashboard 'Discover Features' broadcast.
     * @param string|null   $userId               optional FIXED single-tenant user id (default: null —
     *                                             streams the global snapshot; per-user targeting is a
     *                                             request-side concern via forUser(), not the daemon).
     */
    public function __construct(
        Onelo $client,
        SnapshotStore $store,
        string $strategy = 'auto',
        float $pollInterval = 30.0,
        ?string $appVersion = null,
        ?callable $onDiscoveryRequested = null,
        ?string $userId = null,
    ) {
        $this->strategy = StrategyResolver::resolve($strategy);
        // First-contact hook: log once so an operator watching the daemon's logs
        // knows the stream is live and the shared snapshot is populated.
        $onFirstContact = function () use ($store): void {
            error_log('[onelo.features] listener ready — first snapshot received (config_version ' . $store->configVersion() . ')');
        };

        if ($this->strategy === 'sse') {
            $this->consumer = new SseConsumer(
                store: $store,
                key: $client->key,
                apiUrl: $client->apiUrl,
                connectTimeoutMs: $client->requestTimeoutMs,
                featureEnvironment: $client->featureEnvironment,
                appVersion: $appVersion,
                userId: $userId,
                onDiscoveryRequested: $onDiscoveryRequested,
                onFirstContact: $onFirstContact,
            );
        } else {
            $this->consumer = new PollingConsumer(
                store: $store,
                key: $client->key,
                apiUrl: $client->apiUrl,
                pollInterval: $pollInterval,
                requestTimeoutMs: $client->requestTimeoutMs,
                featureEnvironment: $client->featureEnvironment,
                userId: $userId,
                onDiscoveryRequested: $onDiscoveryRequested,
                onFirstContact: $onFirstContact,
            );
        }
    }

    /**
     * Run the engine — BLOCKS until stop() / a signal / a terminal auth error.
     * Installs graceful SIGTERM/SIGINT handlers, then hands off to the consumer.
     */
    public function run(): void
    {
        $this->installSignalHandlers();
        error_log('[onelo.features] listener starting (strategy=' . $this->strategy . ')');
        $this->consumer->run();
        error_log('[onelo.features] listener stopped');
    }

    /** Signal the consumer to exit after its current connection. Idempotent. */
    public function stop(): void
    {
        $this->consumer->stop();
    }

    /** The resolved strategy ('sse' | 'polling') — useful for tests/logging. */
    public function strategy(): string
    {
        return $this->strategy;
    }

    /** True once the backend has confirmed first contact (first snapshot / poll). */
    public function firstContact(): bool
    {
        return $this->consumer->firstContact();
    }

    /**
     * Install SIGTERM/SIGINT → graceful stop(). No-op when ext-pcntl is missing
     * (e.g. Windows) — the process then relies on the supervisor's hard kill, and
     * the FileStore's atomic writes mean an interrupted write never corrupts the
     * snapshot readers see. async signals are enabled so the handler runs on the
     * next VM tick even while curl_exec() blocks between heartbeats.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        $handler = function (int $signo): void {
            error_log('[onelo.features] listener received signal ' . $signo . ' — shutting down gracefully');
            $this->consumer->stop();
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
