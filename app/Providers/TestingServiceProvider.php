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
        // Bind fake services in testing environment OR when DataCite credentials are missing
        // This allows E2E tests to run without real API credentials
        $shouldUseFake = app()->environment('testing') || $this->shouldUseFakeDataCiteService();
        
        if (! $shouldUseFake) {
            return;
        }

        // Bind fake DataCite service for E2E tests
        $this->app->bind(DataCiteRegistrationService::class, function () {
            return new FakeDataCiteRegistrationService;
        });
    }

    /**
     * Determine if fake DataCite service should be used based on missing credentials
     */
    private function shouldUseFakeDataCiteService(): bool
    {
        // Use fake service if credentials are missing (E2E test scenario)
        $testMode = (bool) config('datacite.test_mode', true);
        
        if ($testMode) {
            $username = config('datacite.test.username');
            $password = config('datacite.test.password');
        } else {
            $username = config('datacite.production.username');
            $password = config('datacite.production.password');
        }
        
        return empty($username) || empty($password);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
