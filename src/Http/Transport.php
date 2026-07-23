<?php

declare(strict_types=1);

namespace Onelo\Http;

/**
 * Pluggable HTTP transport. The default {@see CurlTransport} uses ext-curl so the
 * SDK stays dependency-free; tests inject a fake (the PHP analogue of the Node
 * SDK's `fetchImpl`). A custom transport can wrap Guzzle / Symfony HttpClient.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException on a network/connection failure
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
    ): HttpResponse;
}
