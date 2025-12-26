<?php

namespace App\Providers;

use App\Models\Resource;
use App\Observers\ResourceObserver;
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
        // Register model observers
        Resource::observe(ResourceObserver::class);
    }
}
