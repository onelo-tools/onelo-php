<?php

declare(strict_types=1);

namespace Onelo;

use Onelo\Features\FeaturesClient;
use Onelo\Features\SnapshotStore;
use Onelo\Http\CurlTransport;
use Onelo\Http\Transport;
use Onelo\Monitor\MonitorClient;

/**
 * The Onelo client — the one object you construct. Holds credentials + transport
 * config. Mirrors Python's `onelo.Onelo` key-resolution, with `apiUrl` REQUIRED
 * (per the repo SDK Auth Design Rules — the dashboard snippet pre-fills it).
 *
 * PHP is synchronous and PHP-FPM is request-per-process, so there is no
 * background stream/thread — auth verification is a direct, synchronous call.
 */
final class Onelo
{
    private const ANY_KEY_RE = '/^onelo_(pk|sk)_[a-zA-Z0-9_]+$/';

    public readonly string $key;
    public readonly bool $isSecretKey;
    public readonly string $apiUrl;
    /** Request timeout in milliseconds. */
    public readonly int $requestTimeoutMs;
    public readonly Transport $transport;
    /** Features environment: 'test' | 'live' | null. Selects the snapshot + gates discovery. */
    public readonly ?string $featureEnvironment;

    /** The Monitor module — `$onelo->monitor->init()`, `->captureException($e)`, etc. */
    public readonly MonitorClient $monitor;

    /** The Features module — `$onelo->features->isEnabled('slug')`, `->forUser($id)`, etc. */
    public readonly FeaturesClient $features;

    /**
     * @param SnapshotStore|null $featureStore   snapshot store the Features module reads.
     *                                           Default in-process ArrayStore (FPM per-request
     *                                           poll). Pass a shared FileStore when running the
     *                                           `onelo:features:listen` daemon, so web workers
     *                                           read what the daemon streams.
     * @param bool               $featureAutoRefresh lazy per-request GET /poll when no listener
     *                                           keeps the store fresh. Set false under Octane/
     *                                           Swoole or with a running Listener.
     */
    public function __construct(
        ?string $key = null,
        ?string $publishableKey = null,
        ?string $secretKey = null,
        string $apiUrl = '',
        int $requestTimeoutMs = 5000,
        ?Transport $transport = null,
        ?string $featureEnvironment = null,
        ?SnapshotStore $featureStore = null,
        bool $featureAutoRefresh = true,
        mixed ...$deprecated,
    ) {
        // Forward-compat: warn (don't fatally reject) on unrecognised named args —
        // mirrors Python's Onelo(**deprecated) so a newer dashboard snippet passing
        // an option this SDK build predates never crashes the app. PHP 8 collects
        // unknown NAMED args into the trailing variadic as string-keyed entries.
        foreach ($deprecated as $name => $_ignored) {
            error_log('[onelo] unknown config option ' . json_encode($name) . ' ignored');
        }

        // ── Primary key: exactly one of key / publishableKey / secretKey ──
        $provided = array_values(array_filter(
            [$key, $publishableKey, $secretKey],
            static fn ($k): bool => is_string($k) && $k !== '',
        ));
        if (count($provided) === 0) {
            throw new \InvalidArgumentException('Onelo requires a key — pass key, publishableKey, or secretKey.');
        }
        if (count($provided) > 1) {
            throw new \InvalidArgumentException('Provide exactly one of key, publishableKey, secretKey — not multiple.');
        }
        $chosen = $provided[0];
        if (preg_match(self::ANY_KEY_RE, $chosen, $keyMatch) !== 1) {
            throw new \InvalidArgumentException('Key must match onelo_pk_* or onelo_sk_*, got ' . json_encode($chosen));
        }
        if ($secretKey !== null) {
            $this->isSecretKey = true;
        } elseif ($publishableKey !== null) {
            $this->isSecretKey = false;
        } else {
            // Classify by the captured (pk|sk) prefix group — NOT a floating
            // `_sk_` substring, which would misread a key whose random tail
            // happens to contain "_sk_" (e.g. onelo_pk_live_sk_abc).
            $this->isSecretKey = $keyMatch[1] === 'sk';
        }

        // ── apiUrl — REQUIRED, http(s) with a host ──
        if ($apiUrl === '') {
            throw new \InvalidArgumentException(
                'Onelo requires apiUrl — the dashboard snippet pre-fills it from your environment. '
                . 'Missing apiUrl is a configuration error, not a default.'
            );
        }
        $scheme = parse_url($apiUrl, PHP_URL_SCHEME);
        $host = parse_url($apiUrl, PHP_URL_HOST);
        if (($scheme !== 'http' && $scheme !== 'https') || !is_string($host) || $host === '') {
            throw new \InvalidArgumentException('apiUrl must be an http(s) URL, got ' . json_encode($apiUrl));
        }

        // ── Feature environment (reserved for F2) ──
        $featEnv = $featureEnvironment ?? (getenv('ONELO_FEATURE_ENVIRONMENT') ?: null);
        if (is_string($featEnv)) {
            $featEnv = trim($featEnv);
            if ($featEnv === '') {
                $featEnv = null;
            }
        }
        if ($featEnv !== null && $featEnv !== 'test' && $featEnv !== 'live') {
            throw new \InvalidArgumentException(
                "featureEnvironment must be 'test', 'live', or null (ONELO_FEATURE_ENVIRONMENT), got " . json_encode($featEnv)
            );
        }

        $this->key = $chosen;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->requestTimeoutMs = $requestTimeoutMs;
        $this->transport = $transport ?? new CurlTransport();
        $this->featureEnvironment = $featEnv;
        // Monitor + Features reuse this client's key/apiUrl/transport. Constructed
        // last so they can read the fully-set properties above.
        //
        // Feature-flag correlation (the cross-platform "killer feature"): the
        // monitor stamps the active {name: status} flag snapshot onto every event
        // so the dashboard can correlate an error spike with a flag flip. Wire a
        // LATE-BOUND closure — it reads `$this->features` (assigned on the next
        // line) only at emit time, long after construction, so the monitor-before-
        // features order is not a chicken-and-egg problem. Mirrors Python's
        // `monitor.init(onelo=…)` auto-wiring of `onelo._cache.snapshot`.
        $this->monitor = new MonitorClient($this, fn (): array => $this->features->snapshot());
        $this->features = new FeaturesClient($this, $featureStore, $featureAutoRefresh);
    }
}
