<?php

declare(strict_types=1);

namespace Onelo\Auth;

/**
 * The authenticated Onelo user returned by GET /api/sdk/auth/user. 1:1 with
 * Python's OneloUser. Tolerant of missing fields — email_verified / plan fall
 * back to metadata; the full payload is kept on `raw`.
 */
final class OneloUser
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $plan,
        public readonly array $metadata,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $metadata = $payload['metadata'] ?? null;
        // Non-object metadata (missing, or a JSON list) → {} (matches Python's
        // `isinstance(dict)` guard). A JSON object decodes to an assoc array;
        // a JSON list decodes to a list array.
        if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== [])) {
            $metadata = [];
        }

        // email_verified: top-level wins, otherwise metadata, otherwise false.
        $ev = $payload['email_verified'] ?? null;
        if ($ev === null) {
            $ev = $metadata['email_verified'] ?? false;
        }
        $emailVerified = (bool) $ev;

        // plan: top-level wins, otherwise metadata.plan, otherwise null.
        $plan = $payload['plan'] ?? null;
        if ($plan === null) {
            $plan = $metadata['plan'] ?? null;
        }
        $planValue = $plan === null ? null : (string) $plan;

        $id = self::stringOrEmpty($payload['id'] ?? null);
        $email = self::stringOrEmpty($payload['email'] ?? null);

        return new self($id, $email, $emailVerified, $planValue, $metadata, $payload);
    }

    /**
     * Mirror Python's `str(x or "")`: falsy (null / '' / 0 / 0.0 / false) → ''.
     * NOT PHP's `empty()`, which also treats the STRING "0" as empty — Python
     * keeps "0" (a non-empty string is truthy), so an id of "0" must survive.
     */
    private static function stringOrEmpty(mixed $v): string
    {
        if ($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false) {
            return '';
        }

        return (string) $v;
    }
}
