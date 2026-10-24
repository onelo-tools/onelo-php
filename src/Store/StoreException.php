<?php

declare(strict_types=1);

namespace Onelo\Store;

/** A refused or failed GET /api/sdk/store/plans; `status` is null for a network error. */
final class StoreException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
