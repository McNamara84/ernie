<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        $appUrl = config('app.url');
    
        if (!empty($appUrl)) {
            // Parse the URL to get the path
            $parsedUrl = parse_url($appUrl);
            $path = $parsedUrl['path'] ?? '';
            
            // Force the full URL including path
            URL::forceRootUrl($appUrl);
            
            // Set asset URL
            $assetUrl = config('app.asset_url') ?: $appUrl;
            URL::useAssetOrigin($assetUrl);
            
            // Force scheme
            $scheme = $parsedUrl['scheme'] ?? 'https';
            URL::forceScheme($scheme);
        }
    }
}
