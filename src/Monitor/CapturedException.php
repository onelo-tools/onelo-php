<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/** An exception flattened to a transport-safe shape (type, message, frames, cause). */
final class CapturedException
{
    /** @param list<StackFrame> $frames */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly array $frames,
        public readonly ?CapturedException $cause = null,
    ) {
    }

    /** @return array<string, mixed> wire shape for meta.exception (OTel-compatible) */
    public function toWire(): array
    {
        $out = [
            'type' => $this->type,
            'message' => $this->message,
            'frames' => array_map(static fn (StackFrame $f): array => $f->toWire(), $this->frames),
        ];
        if ($this->cause !== null) {
            $out['cause'] = $this->cause->toWire();
        }

        return $out;
    }
}
