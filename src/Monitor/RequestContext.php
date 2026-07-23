<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * Shared request-context builder for the framework monitor middleware — the PHP
 * analog of Python's `onelo/monitor/_request_context.py` (and the Node
 * `request-context.ts`). Each middleware normalises its framework's request into
 * `{ method, url, headers }` and calls {@see attachRequest}, so every event
 * captured during the request carries scrubbed request context. Scrubbing +
 * drop-list logic lives here, in one place.
 */
final class RequestContext
{
    // Never sent even after scrubbing — bloat with no diagnostic value.
    // 1:1 with Python `_request_context._DROP_HEADERS`.
    private const DROP_HEADERS = ['cookie', 'set-cookie', 'authorization'];

    /**
     * Build the `contexts.request` array: uppercase method, scrubbed full URL,
     * scrubbed headers (lowercased keys) minus the drop-list.
     *
     * @param array<string, string|string[]> $headers
     *
     * @return array<string, mixed>
     */
    public static function build(string $method, ?string $url, array $headers): array
    {
        $ctx = ['method' => strtoupper($method)];

        $scrubbedUrl = Scrub::scrubUrl($url);
        if (is_string($scrubbedUrl) && $scrubbedUrl !== '') {
            $ctx['url'] = $scrubbedUrl;
        }

        $kept = [];
        foreach (Scrub::scrubHeaders($headers) as $name => $value) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::DROP_HEADERS, true)) {
                $kept[$lower] = $value;
            }
        }
        if ($kept !== []) {
            $ctx['headers'] = $kept;
        }

        return $ctx;
    }

    /**
     * Populate the active monitor scope with request context (+ user id). Call
     * early in the request so any captured event carries this data.
     *
     * @param array<string, string|string[]> $headers
     */
    public static function attachRequest(MonitorClient $monitor, string $method, ?string $url, array $headers, ?string $userId = null): void
    {
        $monitor->setContext('request', self::build($method, $url, $headers));
        if ($userId !== null && $userId !== '') {
            $monitor->setUser(['id' => $userId]);
        }
    }

    /** Tag the scope with the response status so dashboards can filter on it. */
    public static function attachResponse(MonitorClient $monitor, int $statusCode): void
    {
        $monitor->setTag('http.status_code', (string) $statusCode);
    }
}
