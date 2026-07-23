<?php

declare(strict_types=1);

namespace Onelo\Laravel;

use Closure;
use Onelo\Monitor\MonitorClient;
use Onelo\Monitor\RequestContext;
use Onelo\Onelo;

/**
 * Laravel monitor middleware. It:
 *   - runs each request in a fresh monitor scope (`resetScope()` — needed under
 *     Octane/Swoole; a no-op under FPM),
 *   - attaches scrubbed request context (method + URL + headers),
 *   - tags the response status,
 *   - reports any thrown error to Onelo Monitor, then RE-THROWS.
 *
 *   // app/Http/Kernel.php — as global middleware (bind Onelo in a provider):
 *   protected $middleware = [ \Onelo\Laravel\OneloMonitor::class, ... ];
 *
 * `$request` is duck-typed (no hard illuminate/* dependency) — the real
 * Illuminate\Http\Request satisfies it (method(), fullUrl(), headers->all()).
 * Requires `$onelo->monitor->init()`.
 */
final class OneloMonitor
{
    private MonitorClient $monitor;

    public function __construct(Onelo $client)
    {
        $this->monitor = $client->monitor;
    }

    /**
     * @param \Illuminate\Http\Request $request
     */
    public function handle(mixed $request, Closure $next): mixed
    {
        $this->monitor->resetScope();
        RequestContext::attachRequest(
            $this->monitor,
            (string) $request->method(),
            (string) $request->fullUrl(),
            $request->headers->all(),
        );

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->monitor->captureException($e);

            throw $e;
        }

        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            RequestContext::attachResponse($this->monitor, (int) $response->getStatusCode());
        }

        return $response;
    }
}
