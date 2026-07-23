<?php

declare(strict_types=1);

namespace Onelo\Auth;

/** Backend returned 401 — token expired, revoked, or malformed. */
class OneloAuthInvalidToken extends OneloAuthError
{
}
