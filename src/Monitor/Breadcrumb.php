<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * A single trace entry attached to the next captured event. 1:1 with Python's
 * Breadcrumb. `category` mirrors the cross-platform enum (navigation/http/feature/
 * lifecycle/user/info/error/log/auth/db). `level` is one of debug|info|warning|
 * error|fatal.
 */
final class Breadcrumb
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly string $category,
        public readonly string $message,
        public readonly float $timestamp,
        public readonly string $level = 'info',
        public readonly ?array $data = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function info(string $message, array $data = []): self
    {
        return new self('info', $message, microtime(true), 'info', $data !== [] ? $data : null);
    }

    /** @param array<string, mixed> $extra */
    public static function http(string $method, string $url, ?int $status = null, ?int $durationMs = null, array $extra = []): self
    {
        // Scrub the URL eagerly so query-param secrets never sit in the buffer.
        $scrubbed = Scrub::scrubUrl($url) ?? $url;
        $data = ['method' => strtoupper($method), 'url' => $scrubbed];
        if ($status !== null) {
            $data['status'] = $status;
        }
        if ($durationMs !== null) {
            $data['duration_ms'] = $durationMs;
        }
        $data = array_merge($data, $extra);

        return new self('http', strtoupper($method) . ' ' . $scrubbed, microtime(true), 'info', $data);
    }

    /** @return array<string, mixed> wire shape for meta.breadcrumbs[] */
    public function toWire(): array
    {
        $out = [
            'category' => $this->category,
            'message' => $this->message,
            'ts' => $this->timestamp,
            'level' => $this->level,
        ];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
