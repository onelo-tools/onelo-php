<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/** A single frame inside a captured stack trace. OTel-compatible field names. */
final class StackFrame
{
    public function __construct(
        public readonly ?string $function,
        public readonly ?string $class,
        public readonly ?string $filename,
        public readonly ?int $lineno,
        public readonly bool $inApp,
        public readonly ?string $contextLine = null,
    ) {
    }

    /** @return array<string, mixed> wire shape for meta.exception.frames[] */
    public function toWire(): array
    {
        $out = [];
        if ($this->function !== null) {
            $out['function'] = $this->function;
        }
        if ($this->class !== null) {
            $out['module'] = $this->class; // OTel calls the PHP class the "module"
        }
        if ($this->filename !== null) {
            $out['filename'] = $this->filename;
        }
        if ($this->lineno !== null) {
            $out['lineno'] = $this->lineno;
        }
        $out['in_app'] = $this->inApp;
        if ($this->contextLine !== null) {
            $out['context_line'] = Scrub::scrubText($this->contextLine) ?? $this->contextLine;
        }

        return $out;
    }
}
