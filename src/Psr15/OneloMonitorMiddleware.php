<?php

declare(strict_types=1);

namespace Onelo\Psr15;

use Onelo\Monitor\MonitorClient;
use Onelo\Monitor\RequestContext;
use Onelo\Onelo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Framework-agnostic PSR-15 monitor middleware (Slim, Mezzio, Symfony via a PSR
 * bridge, any PSR-15 stack). It:
 *   - runs each request in a fresh monitor scope (`resetScope()` — a no-op under
 *     PHP-FPM where the process is already per-request, essential under Octane/Swoole),
 *   - attaches scrubbed request context (method + URL + headers) so any captured
 *     event carries it,
 *   - tags the response status,
 *   - reports any thrown error to Onelo Monitor, then RE-THROWS so your app's own
 *     error handling still runs.
 *
 * Place it EARLY in the pipeline (before your routes). Requires the monitor to be
 * initialised (`$onelo->monitor->init()`).
 */
final class OneloMonitorMiddleware implements MiddlewareInterface
{
    private MonitorClient $monitor;

    public function __construct(Onelo $client)
    {
        $this->monitor = $client->monitor;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->monitor->resetScope();
        RequestContext::attachRequest(
            $this->monitor,
            $request->getMethod(),
            (string) $request->getUri(),
            $request->getHeaders(),
        );

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $this->monitor->captureException($e);

            throw $e;
        }

        RequestContext::attachResponse($this->monitor, $response->getStatusCode());

        return $response;
    }
}
