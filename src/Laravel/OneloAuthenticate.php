<?php

declare(strict_types=1);

namespace Onelo\Laravel;

use Closure;
use Onelo\Auth\AuthHttpError;
use Onelo\Auth\Resolver;
use Onelo\Onelo;

/**
 * Laravel route middleware that verifies an Onelo bearer token.
 *
 *   Route::get('/me', fn (Request $r) => ['id' => $r->attributes->get('oneloUser')->id])
 *       ->middleware(OneloAuthenticate::class);
 *
 * (Bind Onelo in a service provider, or register a middleware alias with an
 * instance — the constructor needs your Onelo client.)
 *
 * Status mapping: 401 missing/invalid · 403 forbidden/gate · 503 backend outage.
 * On success attaches the verified user at $request->attributes->get('oneloUser').
 *
 * `$request` is duck-typed (no hard `illuminate/*` dependency) — the real
 * Illuminate\Http\Request satisfies it at runtime; `response()` resolves to
 * Laravel's global helper.
 *
 * HTTP-request context only: the error branch uses Laravel's `response()` helper,
 * which needs a booted HTTP container. That always holds for route middleware
 * (its intended use). For non-HTTP contexts (a queue worker, a bare console
 * script), call {@see \Onelo\Auth\Verify::verifyToken()} directly instead.
 */
final class OneloAuthenticate
{
    private Resolver $resolver;

    /** @param array<string, mixed> $options see {@see Resolver::fromOptions()} */
    public function __construct(Onelo $client, private readonly bool $optional = false, array $options = [])
    {
        $this->resolver = Resolver::fromOptions($client, $options);
    }

    /** Like the default, but injects oneloUser=null instead of 401/403 (503 still surfaces). */
    public static function optional(Onelo $client, array $options = []): self
    {
        return new self($client, true, $options);
    }

    /**
     * @param \Illuminate\Http\Request $request
     */
    public function handle(mixed $request, Closure $next): mixed
    {
        $auth = $request->header('Authorization');
        $queryToken = $request->query('token');
        try {
            $user = $this->resolver->resolve(
                is_string($auth) ? $auth : null,
                is_string($queryToken) ? $queryToken : null,
            );
        } catch (AuthHttpError $e) {
            if ($this->optional && ($e->status === 401 || $e->status === 403)) {
                $request->attributes->set('oneloUser', null);

                return $next($request);
            }

            return response()->json($e->body, $e->status); // 503 surfaces
        }

        $request->attributes->set('oneloUser', $user);

        return $next($request);
    }
}
