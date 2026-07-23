<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Internal signal carrying the (status, body) an adapter should return. Each
 * adapter catches this and renders it in its framework's idiom. Mirrors Python's
 * Flask `_AuthError` and the Node `AuthHttpError`.
 */
final class AuthHttpError extends \RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        $error = $body['error'] ?? null;
        parent::__construct(is_string($error) ? $error : 'auth_error');
    }
}
