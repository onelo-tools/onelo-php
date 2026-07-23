<?php

declare(strict_types=1);

namespace Onelo\Http;

/** Default transport — ext-curl, zero third-party dependencies. */
final class CurlTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
    ): HttpResponse {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException('failed to initialise curl');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $err = curl_error($ch);
            throw new TransportException('network error: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // No curl_close(): the CurlHandle is freed by GC when $ch leaves scope.
        // curl_close() has been a no-op since PHP 8.0 and is DEPRECATED since PHP
        // 8.5 — calling it emits a Deprecated notice that, with display_errors=1
        // (common in dev / `php -S` / many Docker PHP images), leaks into the HTTP
        // response body and corrupts the JSON. Do NOT re-add it.
        return new HttpResponse($status, $respHeaders, (string) $respBody);
    }
}
