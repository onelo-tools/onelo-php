<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Base class for all auth verification failures. 1:1 with Python's taxonomy:
 *
 *   OneloAuthError
 *   ├── OneloAuthMissingToken   → 401
 *   ├── OneloAuthInvalidToken   → 401
 *   ├── OneloAuthForbidden      → 403
 *   └── OneloAuthUnavailable    → 503
 *       └── OneloAuthRateLimited → still 503, but the retry loop skips it
 */
class OneloAuthError extends \RuntimeException
{
}
