<?php

namespace App\Providers;

use App\Services\DatabaseInstaller;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        if (! $this->app->make(DatabaseInstaller::class)->ready()) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'file',
            ]);
        }
    }
}
