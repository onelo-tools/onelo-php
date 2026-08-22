# onelo/onelo-php

The Onelo SDK for PHP backends — verify users, gate features by plan, and monitor errors. Ships adapters for Laravel and any PSR-15 framework.

Part of [Onelo](https://onelo.tools): hosted sign-in, a paywall on **your own Stripe** account, plan-gated feature flags, uptime monitoring, in-app feedback, a public roadmap and a waitlist.

## Install

The package is installed from GitHub rather than Packagist:

```bash
composer config repositories.onelo vcs https://github.com/onelo-tools/onelo-php
composer require onelo/onelo-php
```

## Quick start

```php
<?php
use Onelo\Onelo;

// Authenticate with your SERVER SECRET key (onelo_sk_live_…) — NOT a publishable
// key. Read it from the environment; never hard-code a secret in source.
$onelo = new Onelo(
    secretKey: $_ENV['ONELO_SECRET_KEY'],   // onelo_sk_live_…  (NEVER commit)
    apiUrl: 'https://api.onelo.tools',
);
```

Both a key and `apiUrl` are required — the constructor throws if either is missing or malformed.

## Laravel

The service provider is auto-discovered. Bind the client once in a service provider:

```php
$this->app->singleton(Onelo::class, fn () => $onelo);
```

Then protect routes with the auth middleware:

```php
use Onelo\Laravel\OneloAuthenticate;

Route::get('/me', function (Request $request) {
    $user = $request->attributes->get('oneloUser');
    return ['id' => $user->id, 'email' => $user->email];
})->middleware(OneloAuthenticate::class);
```

For monitoring, prepend `Onelo\Laravel\OneloMonitor` to the global middleware stack in `app/Http/Kernel.php`.

## PSR-15

```php
use Onelo\Psr15\OneloAuthMiddleware;
use Onelo\Psr15\OneloMonitorMiddleware;

$app->add(new OneloMonitorMiddleware($onelo));            // add first
$app->add(new OneloAuthMiddleware($onelo, $responseFactory));

// then, inside a handler:
$user = $request->getAttribute('oneloUser');
```

## Any framework

```php
use Onelo\Auth\Verify;

$token = Verify::extractBearerToken($authorizationHeader);
$user  = Verify::verifyToken($onelo, $token);
```

Failures map to the status you'd expect: invalid or missing token → **401**, forbidden or a failed plan gate → **403**, rate-limited or Onelo unreachable → **503**, so an outage never silently degrades into anonymous access.

## Modules

| Module | What it does | Key API |
|---|---|---|
| **Auth** | Verify tokens issued to your users by Onelo | `Verify::verifyToken()`, `Verify::verifyWithRetry()`, `Verify::extractBearerToken()` |
| `$onelo->features` | Plan-gated feature flags | `isEnabled()`, `feature()`, `declare()`, `forUser()`, `ready()` |
| `$onelo->monitor` | Error and event reporting, breadcrumbs | `init()`, `captureException()`, `track()`, `setUser()`, `flush()` |

Feature checks **fail closed** — if the snapshot is unavailable a flag reads as hidden, rather than accidentally granting access.

```php
$onelo->features->declare(['export-api']);

if ($onelo->features->isEnabled('export-api')) { /* … */ }

// Per-user resolution against their real plan
$flags = $onelo->features->forUser($userId);
```

### Keeping flags fresh

Under **PHP-FPM** the defaults work as-is: each request refreshes what it needs, and monitor events are flushed on shutdown.

For a long-lived worker, or to avoid a per-request refresh, run the listener as a supervised process and let it maintain a shared snapshot:

```bash
php artisan onelo:features:listen
```

Then construct the client with `featureAutoRefresh: false` and a `FileStore`.

## Persistent runtimes (Octane, Swoole, RoadRunner, queue workers)

The SDK detects a persistent runtime and warns if per-request isolation is missing. Reset state between requests so one request's user or scope never leaks into the next:

```php
$onelo->monitor->resetScope();
$onelo->features->resetForRequest();
```

Also set `featureAutoRefresh: false` there, or when the listener command is running.

## Requirements

- **PHP 8.1+**
- Extensions: `ext-curl`, `ext-json`
- PSR-7 / PSR-15 / PSR-17 interfaces for the middleware adapters

## Links

- **Docs:** [onelo.tools/docs](https://onelo.tools/docs)
- **Dashboard:** [onelo.tools](https://onelo.tools) — your app's snippet comes pre-filled with your keys
- **Issues:** please report them on this repository

## License

MIT
