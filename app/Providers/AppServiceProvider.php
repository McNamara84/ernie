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
        // Set the asset URL for production when behind a proxy with path prefix
        if (config('app.env') === 'production' && config('app.url')) {
            $this->configureUrlGeneration();
        }
    }

    /**
     * Configure URL generation for production with path prefix
     */
    private function configureUrlGeneration(): void
    {
        // Force the asset URL to include the path prefix
        if ($assetUrl = config('app.asset_url')) {
            URL::forceRootUrl($assetUrl);
        } else {
            URL::forceRootUrl(config('app.url'));
        }

        // Ensure HTTPS is used if the app URL uses HTTPS
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
