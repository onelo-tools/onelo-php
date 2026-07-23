<?php

declare(strict_types=1);

namespace Onelo\Auth;

/** No bearer token was supplied (header missing / malformed / empty). */
class OneloAuthMissingToken extends OneloAuthError
{
}
