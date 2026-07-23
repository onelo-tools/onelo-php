<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Backend returned 403 — token valid but principal not allowed. Also thrown by
 * the resolver when a user fails an extra gate (requireEmailVerified / requirePlan).
 */
class OneloAuthForbidden extends OneloAuthError
{
}
