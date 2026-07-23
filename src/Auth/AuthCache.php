<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Pluggable cache for verified user tokens. Tokens are never cached in
 * plaintext — the resolver passes a sha256 hash as the key.
 *
 * NOTE for PHP-FPM: the default {@see InMemoryAuthCache} is REQUEST-SCOPED (the
 * process dies after the request), so it only de-dupes repeated verifications of
 * the same token WITHIN one request. For cross-request caching, implement this
 * interface over APCu / Redis and pass it to the adapter.
 */
interface AuthCache
{
    public function get(string $tokenHash): ?OneloUser;

    public function set(string $tokenHash, OneloUser $user, float $ttlSeconds): void;

    public function invalidate(string $tokenHash): void;
}
