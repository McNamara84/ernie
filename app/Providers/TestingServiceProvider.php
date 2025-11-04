<?php

namespace App\Providers;

use App\Services\DataCiteRegistrationService;
use App\Services\FakeDataCiteRegistrationService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for testing environment
 * 
 * Binds fake services in testing environment to avoid external API calls
 * Used by Playwright E2E tests where HTTP mocking is not available
 */
class TestingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Only bind fake services in testing environment
        if (! app()->environment('testing')) {
            return;
        }

        // Bind fake DataCite service for E2E tests
        $this->app->bind(DataCiteRegistrationService::class, function () {
            return new FakeDataCiteRegistrationService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
