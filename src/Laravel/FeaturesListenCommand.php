<?php

declare(strict_types=1);

namespace Onelo\Laravel;

use Illuminate\Console\Command;
use Onelo\Features\FileStore;
use Onelo\Features\Listener;
use Onelo\Onelo;

/**
 * `php artisan onelo:features:listen` — the long-lived Features listener.
 *
 * Run it as a SUPERVISED daemon (systemd / supervisor / `php artisan queue:work`
 * style) alongside PHP-FPM. It holds the SSE (or polling) stream and writes the
 * feature snapshot to a shared {@see FileStore}; your FPM web workers read the
 * SAME file. Configure the web app's Onelo client with the same store + no
 * auto-refresh:
 *
 *   $onelo = new Onelo(
 *       secretKey: env('ONELO_SECRET_KEY'),
 *       apiUrl: env('ONELO_API_URL'),
 *       featureStore: new FileStore(env('ONELO_FEATURES_STORE_PATH')),
 *       featureAutoRefresh: false,   // the daemon keeps the store fresh
 *   );
 *
 * Under Laravel Octane / Swoole there is a persistent process already, so you can
 * run the Listener in-process with an ArrayStore instead of this command.
 */
final class FeaturesListenCommand extends Command
{
    protected $signature = 'onelo:features:listen {--strategy= : sse|polling|auto (default auto→sse)} {--store= : shared snapshot file path} {--poll-interval=30}';

    protected $description = 'Stream Onelo feature flags into a shared store so PHP-FPM workers read live state.';

    public function handle(): int
    {
        $key = getenv('ONELO_SECRET_KEY') ?: getenv('ONELO_PUBLISHABLE_KEY');
        $apiUrl = getenv('ONELO_API_URL');
        if (!is_string($key) || $key === '' || !is_string($apiUrl) || $apiUrl === '') {
            $this->error('ONELO_SECRET_KEY (or ONELO_PUBLISHABLE_KEY) and ONELO_API_URL must be set.');

            return self::FAILURE;
        }

        $storePath = (string) ($this->option('store') ?: getenv('ONELO_FEATURES_STORE_PATH') ?: (sys_get_temp_dir() . '/onelo/features.json'));
        $strategy = (string) ($this->option('strategy') ?: getenv('ONELO_FEATURES_STRATEGY') ?: 'auto');
        $pollInterval = (float) $this->option('poll-interval');

        $isSecret = str_contains($key, '_sk_');
        $onelo = new Onelo(
            secretKey: $isSecret ? $key : null,
            publishableKey: $isSecret ? null : $key,
            apiUrl: $apiUrl,
            featureEnvironment: (getenv('ONELO_FEATURE_ENVIRONMENT') ?: null),
        );
        $store = new FileStore($storePath);

        $this->info("Onelo features listener starting (strategy={$strategy}, store={$storePath}). Ctrl-C / SIGTERM to stop.");
        // Blocks until SIGTERM/SIGINT (handled inside Listener) — the whole point.
        (new Listener($onelo, $store, $strategy, $pollInterval))->run();
        $this->info('Onelo features listener stopped.');

        return self::SUCCESS;
    }
}
