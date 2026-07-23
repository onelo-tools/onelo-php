<?php

declare(strict_types=1);

namespace Onelo\Monitor;

/**
 * Per-request scope: user, tags, contexts, extra, breadcrumbs. PHP-FPM runs one
 * request per process, so a single instance-level scope IS request-isolated — no
 * contextvars/ALS needed (unlike Python/Node). Persistent runtimes (Swoole,
 * RoadRunner, Laravel Octane) reuse the process across requests, so call
 * {@see reset()} at the start of each request there.
 */
final class Scope
{
    private ?array $user = null;
    /** @var array<string, string> */
    private array $tags = [];
    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];
    /** @var array<string, mixed> */
    private array $extra = [];
    /** @var list<Breadcrumb> */
    private array $breadcrumbs = [];

    public function __construct(private readonly int $breadcrumbCapacity = 100)
    {
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    /** @param array<string, mixed> $value */
    public function setContext(string $key, array $value): void
    {
        $this->contexts[$key] = $value;
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    public function addBreadcrumb(Breadcrumb $crumb): void
    {
        $this->breadcrumbs[] = $crumb;
        if (count($this->breadcrumbs) > $this->breadcrumbCapacity) {
            array_shift($this->breadcrumbs);
        }
    }

    public function reset(): void
    {
        $this->user = null;
        $this->tags = [];
        $this->contexts = [];
        $this->extra = [];
        $this->breadcrumbs = [];
    }

    // ── read accessors (used by cross-process carrier serialisation) ──────────

    /** @return array<string, mixed>|null */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /** @return array<string, string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return array<string, array<string, mixed>> */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    /** @return list<Breadcrumb> */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /** Layer this scope's data onto an outgoing event. */
    public function applyTo(MonitorEvent $event): void
    {
        if ($this->user !== null && $event->userId === null) {
            $uid = $this->user['id'] ?? null;
            // `!== null` not truthiness: 0 / '' / false are valid opaque ids.
            if ($uid !== null) {
                $event->userId = (string) $uid;
            }
        }
        if ($this->tags !== []) {
            $bucket = self::objectBucket($event->meta, 'tags');
            if ($bucket !== null) {
                $event->meta['tags'] = array_merge($bucket, $this->tags);
            }
        }
        if ($this->contexts !== []) {
            $bucket = self::objectBucket($event->meta, 'contexts');
            if ($bucket !== null) {
                foreach ($this->contexts as $k => $v) {
                    $bucket[$k] = $v;
                }
                $event->meta['contexts'] = $bucket;
            }
        }
        if ($this->extra !== []) {
            $bucket = self::objectBucket($event->meta, 'extra');
            if ($bucket !== null) {
                $event->meta['extra'] = array_merge($bucket, $this->extra);
            }
        }
        if ($this->breadcrumbs !== []) {
            $existing = $event->meta['breadcrumbs'] ?? [];
            if (is_array($existing)) {
                foreach ($this->breadcrumbs as $crumb) {
                    $existing[] = $crumb->toWire();
                }
                $event->meta['breadcrumbs'] = $existing;
            }
        }
    }

    /**
     * Return meta[$key] as an array to merge into, or null (skip) if the caller
     * already put a non-array there — never throw / clobber their value.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<mixed>|null
     */
    private static function objectBucket(array $meta, string $key): ?array
    {
        if (!array_key_exists($key, $meta)) {
            return [];
        }

        return is_array($meta[$key]) ? $meta[$key] : null;
    }
}
