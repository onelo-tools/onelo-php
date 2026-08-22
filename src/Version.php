<?php

declare(strict_types=1);

namespace Onelo;

/**
 * Single source of truth for the SDK version. Bump on EVERY src change.
 * Per repo CLAUDE.md: also update LATEST_SDK_VERSIONS['onelo-php'] in
 * frontend/lib/sdk-version.ts so the dashboard can warn about outdated SDKs.
 */
final class Version
{
    public const VERSION = '0.6.1';
}
