<?php

declare(strict_types=1);

namespace Onelo\Http;

/** A transport-agnostic HTTP response (status + lower-cased headers + raw body). */
final class HttpResponse
{
    /** @param array<string, string> $headers header names MUST be lower-cased */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** Case-insensitive header lookup. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decode the body as JSON.
     *
     * @throws \JsonException on invalid JSON
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
