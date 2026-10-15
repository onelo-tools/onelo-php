<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * #520: a per-user Features call from a server must be signed with the SECRET
 * key. The backend trusts a caller-supplied userId only from a secret-key
 * caller, classifying the key by its `onelo_sk_` prefix exactly as here. A
 * publishable key is public — anyone can copy it and name any user — so the
 * call is refused before it is sent. Anonymous/global calls with a publishable
 * key are unaffected.
 */
final class SecretKeyRequired extends FeaturesException
{
    public static function assertFor(string $key, string $call): void
    {
        if (str_starts_with($key, 'onelo_sk_')) {
            return;
        }
        throw new self(
            $call . ' needs the secret key: per-user Features calls from a server are trusted only when '
            . 'signed with a secret key, and this client was configured with a publishable key (onelo_pk_…) — '
            . 'use your secret key (onelo_sk_…) on the server: new Onelo(secretKey: getenv(\'ONELO_SECRET_KEY\'), '
            . 'apiUrl: …). Anonymous calls (feature(), isEnabled(), declare()) keep working with a publishable key.'
        );
    }
}
