<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * Backend returned 429 Too Many Requests. Subclass of {@see OneloAuthUnavailable}
 * so "backend unavailable → 503" handlers keep working — BUT the retry loop skips
 * it (retrying a rate-limit immediately only makes it worse). `retryAfter` carries
 * the parsed Retry-After seconds when the backend supplied one, else null.
 */
class OneloAuthRateLimited extends OneloAuthUnavailable
{
    public function __construct(string $message = '', public readonly ?float $retryAfter = null)
    {
        parent::__construct($message);
    }
}
