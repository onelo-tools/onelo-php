<?php

declare(strict_types=1);

namespace Onelo\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel auto-discovered provider. Registers the Features listener command
 * (`php artisan onelo:features:listen`). Only loaded in a Laravel app (via
 * composer extra.laravel.providers); non-Laravel projects never reference it.
 */
final class OneloServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FeaturesListenCommand::class]);
        }
    }
}
