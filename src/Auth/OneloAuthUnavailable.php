<?php

declare(strict_types=1);

namespace Onelo\Auth;

/** Onelo backend returned a 5xx (retries exhausted) or the network failed. */
class OneloAuthUnavailable extends OneloAuthError
{
}
