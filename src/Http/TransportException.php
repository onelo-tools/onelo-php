<?php

declare(strict_types=1);

namespace Onelo\Http;

/** Thrown by a Transport on a network/connection failure (DNS, timeout, reset). */
class TransportException extends \RuntimeException
{
}
