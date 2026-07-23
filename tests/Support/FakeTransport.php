<?php

declare(strict_types=1);

namespace Onelo\Tests\Support;

use Onelo\Http\HttpResponse;
use Onelo\Http\Transport;
use Onelo\Http\TransportException;
use Onelo\Onelo;

/** A Transport that replays a queue of canned responses (or throws). */
final class FakeTransport implements Transport
{
    public int $calls = 0;
    /** @var array<string, string> */
    public array $lastHeaders = [];
    public ?string $lastUrl = null;
    public ?string $lastBody = null;

    /** @var list<array{status: int, body: string, headers: array<string, string>}> */
    private array $queue;

    private bool $throw = false;

    /** @param list<array{status: int, body: string, headers?: array<string, string>}> $responses */
    public function __construct(array $responses = [])
    {
        $this->queue = array_map(
            static fn (array $r): array => [
                'status' => $r['status'],
                'body' => $r['body'],
                'headers' => $r['headers'] ?? [],
            ],
            $responses,
        );
    }

    /** One JSON response. */
    public static function json(int $status, mixed $body, array $headers = []): self
    {
        return new self([['status' => $status, 'body' => (string) json_encode($body), 'headers' => $headers]]);
    }

    /** Raw body (for invalid-JSON tests). */
    public static function raw(int $status, string $body, array $headers = []): self
    {
        return new self([['status' => $status, 'body' => $body, 'headers' => $headers]]);
    }

    /** A sequence of [status, jsonBody, headers?] responses. */
    public static function sequence(array $responses): self
    {
        return new self(array_map(
            static fn (array $r): array => [
                'status' => $r[0],
                'body' => (string) json_encode($r[1]),
                'headers' => $r[2] ?? [],
            ],
            $responses,
        ));
    }

    public static function throwing(): self
    {
        $t = new self();
        $t->throw = true;

        return $t;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse
    {
        $this->calls++;
        $this->lastHeaders = $headers;
        $this->lastUrl = $url;
        $this->lastBody = $body;
        if ($this->throw) {
            throw new TransportException('network down');
        }
        $i = min($this->calls - 1, count($this->queue) - 1);
        $r = $this->queue[$i];

        return new HttpResponse($r['status'], array_change_key_case($r['headers'], CASE_LOWER), $r['body']);
    }
}
