<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // URL configuration is handled by the SetUrlRoot middleware for HTTP requests.
        // We do NOT configure URL generation here because:
        // 1. During Wayfinder route generation (npm run build), we want relative URLs
        // 2. The middleware handles runtime URL configuration correctly
        // 3. Calling forceRootUrl with APP_URL (which includes path prefix) here
        //    causes double-protocol issues like "https://https://" or "//https://"
    }
}
