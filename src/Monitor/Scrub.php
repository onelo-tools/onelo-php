<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * PII / secret scrubbing — 1:1 with the Node/Python scrubber (which mirrors the
 * backend denylist). ASCII-only lookarounds (not `\b`) so a secret padded with
 * non-ASCII can't escape the boundary; underscore is intentionally NOT a boundary
 * char. PHP PCRE `\w` is ASCII (no /u flag), matching the intent.
 */
final class Scrub
{
    public const REDACTED = '[REDACTED]';
    private const MAX_DEPTH = 10;

    private const SENSITIVE_QUERY_KEYS = [
        'token', 'access_token', 'refresh_token', 'id_token',
        'key', 'api_key', 'apikey',
        'secret', 'client_secret',
        'password', 'passwd',
        'auth', 'authorization',
        'code',
        'session',
    ];

    private const PII_KEY_SUBSTRINGS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'cookie', 'credential', 'private_key',
        'client_secret', 'cvv', 'ssn',
    ];

    // Full-header denylist (8) — 1:1 with Python `_scrub.SENSITIVE_HEADERS`.
    private const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'proxy-authorization',
        'x-api-key', 'x-auth-token', 'x-onelo-secret', 'www-authenticate',
    ];

    private const LB = '(?<![A-Za-z0-9])';
    private const LA = '(?![A-Za-z0-9])';

    private static bool $strictEmail = false;
    /** @var array<string, true> */
    private static array $extraKeys = [];

    public static function setStrictEmailScrub(bool $enabled): void
    {
        self::$strictEmail = $enabled;
    }

    /** @param iterable<string>|null $names */
    public static function configureSensitiveKeys(?iterable $names): void
    {
        self::$extraKeys = [];
        foreach ($names ?? [] as $n) {
            self::$extraKeys[strtolower(str_replace('-', '_', $n))] = true;
        }
    }

    /** @return list<string> */
    private static function valuePatterns(): array
    {
        $lb = self::LB;
        $la = self::LA;

        return [
            "#{$lb}Bearer\\s+[A-Za-z0-9\\-._~+/]+=*#i",
            "#{$lb}eyJ[A-Za-z0-9\\-_]+\\.[A-Za-z0-9\\-_]+\\.[A-Za-z0-9\\-_]+{$la}#",
            "#{$lb}(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,}{$la}#",
            "#{$lb}onelo_(?:pk|sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}{$la}#",
            // Credit cards — separated
            "#{$lb}(?:4[0-9]{3}|5[1-5][0-9]{2}|3[47][0-9]{2}|6(?:011|5[0-9]{2}))[\\s-][0-9]{4}[\\s-][0-9]{4}[\\s-][0-9]{4}{$la}#",
            // Credit cards — unseparated Visa/MC/Discover
            "#{$lb}(?:4|5[1-5]|6(?:011|5))[0-9]{14,}(?![0-9])#",
            // Credit cards — unseparated Amex
            "#{$lb}3[47][0-9]{13,}(?![0-9])#",
        ];
    }

    // Domain labels are `[\w-]+` (no dot) joined by literal dots — every quantifier
    // is anchored, so no ReDoS. Slightly looser than an RFC TLD; over-redaction is
    // safe for a PII scrubber.
    private static function emailPattern(): string
    {
        return '#' . self::LB . '[\\w.+-]+@[\\w-]+(?:\\.[\\w-]+)+' . self::LA . '#';
    }

    public static function scrubText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $out = preg_replace(self::valuePatterns(), self::REDACTED, $text) ?? $text;
        if (self::$strictEmail) {
            $out = preg_replace(self::emailPattern(), self::REDACTED, $out) ?? $out;
        }

        return $out;
    }

    public static function isPiiKey(string $key): bool
    {
        $normalised = strtolower(str_replace('-', '_', $key));
        foreach (self::PII_KEY_SUBSTRINGS as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }
        foreach (array_keys(self::$extraKeys) as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        // Segment "key" catches x-anthropic-key etc without matching "monkey".
        return in_array('key', explode('_', $normalised), true);
    }

    /**
     * Redact sensitive request/response headers: a value is redacted when its
     * (lowercased) name is in the full-header denylist OR is a PII key. Multi-value
     * headers are joined before redaction. 1:1 with Node/Python `scrub_headers`.
     *
     * @param array<string, string|string[]> $headers
     *
     * @return array<string, string>
     */
    public static function scrubHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $name = (string) $k;
            $value = is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
            $out[$name] = (in_array(strtolower($name), self::SENSITIVE_HEADERS, true) || self::isPiiKey($name))
                ? self::REDACTED
                : $value;
        }

        return $out;
    }

    public static function scrubUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return self::scrubText($url);
        }
        $query = '';
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $query = self::redactQuery($params);
        }
        $path = isset($parts['path']) ? (self::scrubText($parts['path']) ?? $parts['path']) : '';
        $fragment = $parts['fragment'] ?? '';
        if ($fragment !== '' && str_contains($fragment, '=')) {
            parse_str($fragment, $fragParams);
            $fragment = self::redactQuery($fragParams);
        } elseif ($fragment !== '') {
            $fragment = self::scrubText($fragment) ?? $fragment;
        }

        $out = '';
        if (isset($parts['scheme'])) {
            $out .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $out .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= $path;
        if ($query !== '') {
            $out .= '?' . $query;
        }
        if ($fragment !== '') {
            $out .= '#' . $fragment;
        }

        return $out;
    }

    /** @param array<string, mixed> $params */
    private static function redactQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            $sensitive = in_array(strtolower((string) $k), self::SENSITIVE_QUERY_KEYS, true);
            if (is_array($v)) {
                // Array-style params (a[]=1&a[]=2) — parse_str yields an array.
                // JSON-encode non-sensitive ones instead of casting to string
                // (which would emit "Array" + an E_WARNING).
                $val = $sensitive ? self::REDACTED : (string) json_encode($v);
            } else {
                $val = $sensitive ? self::REDACTED : (string) $v;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode($val);
        }

        return implode('&', $pairs);
    }

    /**
     * Recursively scrub a JSON-shaped meta array. Returns a new array; appends
     * `_onelo_redacted` listing redacted keys.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public static function scrubMeta(array $meta): array
    {
        $redacted = [];
        $cleaned = self::scrubArray($meta, $redacted, 0);
        if ($redacted !== []) {
            $keys = array_keys($redacted);
            sort($keys);
            $cleaned['_onelo_redacted'] = $keys;
        }

        return $cleaned;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, true> $redacted
     *
     * @return array<mixed>
     */
    private static function scrubArray(array $value, array &$redacted, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            $redacted['_onelo_depth_exceeded'] = true;

            return ['_onelo_depth_exceeded' => true];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($k === '_onelo_redacted') {
                $out[$k] = $v;
                continue;
            }
            if (is_string($k) && self::isPiiKey($k)) {
                $redacted[$k] = true;
                $out[$k] = self::REDACTED;
                continue;
            }
            $out[$k] = self::scrubValue($v, $redacted, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<string, true> $redacted
     */
    private static function scrubValue(mixed $value, array &$redacted, int $depth): mixed
    {
        if (is_string($value)) {
            return self::scrubText($value);
        }
        if (is_array($value)) {
            return self::scrubArray($value, $redacted, $depth);
        }

        return $value;
    }
}
