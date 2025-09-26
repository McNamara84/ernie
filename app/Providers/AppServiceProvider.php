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
        // Only configure URL generation for route generation, not assets
        // Assets should remain at root level for proper serving
        try {
            if ($this->app->environment('production')) {
                $this->configureUrlGeneration();
            }
        } catch (\Exception $e) {
            // Log error but don't fail completely
            if ($this->app->bound('log')) {
                logger()->error('Failed to configure URL generation: ' . $e->getMessage());
            }
        }
    }

    /**
     * Configure URL generation for production with path prefix
     */
    private function configureUrlGeneration(): void
    {
        try {
            $appUrl = config('app.url');
            
            // Only configure if we have valid URLs
            if ($appUrl) {
                // Only set root URL for route generation, not assets
                URL::forceRootUrl($appUrl);
                
                // Force HTTPS if the URL uses HTTPS
                if (str_starts_with($appUrl, 'https://')) {
                    URL::forceScheme('https');
                }
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the application
        }
    }
}
