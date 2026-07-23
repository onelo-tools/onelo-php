<?php

declare(strict_types=1);

namespace Onelo\Psr15;

use Onelo\Auth\AuthHttpError;
use Onelo\Auth\Resolver;
use Onelo\Onelo;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Framework-agnostic PSR-15 auth middleware — works with Slim, Mezzio, Symfony
 * (via a PSR bridge), and any PSR-15 stack. Verifies the Onelo bearer token,
 * attaches the user as the `oneloUser` request attribute, and returns a JSON
 * error response on failure (401 / 403 / 503).
 *
 * Pass a PSR-17 ResponseFactory (every PSR-15 app has one) so the middleware can
 * build error responses without a hard dependency on a specific HTTP library.
 */
final class OneloAuthMiddleware implements MiddlewareInterface
{
    private Resolver $resolver;

    /** @param array<string, mixed> $options see {@see Resolver::fromOptions()} */
    public function __construct(
        Onelo $client,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly bool $optional = false,
        array $options = [],
    ) {
        $this->resolver = Resolver::fromOptions($client, $options);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authLine = $request->getHeaderLine('Authorization');
        $auth = $authLine !== '' ? $authLine : null;
        $queryToken = $request->getQueryParams()['token'] ?? null;

        try {
            $user = $this->resolver->resolve($auth, is_string($queryToken) ? $queryToken : null);
        } catch (AuthHttpError $e) {
            if ($this->optional && ($e->status === 401 || $e->status === 403)) {
                return $handler->handle($request->withAttribute('oneloUser', null));
            }

            return $this->jsonResponse($e->status, $e->body); // 503 surfaces
        }

        return $handler->handle($request->withAttribute('oneloUser', $user));
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $resp = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'application/json');
        $resp->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $resp;
    }
}
