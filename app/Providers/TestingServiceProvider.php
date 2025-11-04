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
        // Bind fake DataCite service with deferred resolution
        // This allows the config to be fully loaded before checking credentials
        $this->app->bind(DataCiteRegistrationService::class, function ($app) {
            // Only use fake service if credentials are missing (E2E test scenario)
            // Pest tests set credentials explicitly and use Http::fake() instead
            $shouldUseFake = $this->shouldUseFakeDataCiteService($app);
            
            if ($shouldUseFake) {
                \Illuminate\Support\Facades\Log::info('TestingServiceProvider: Using FakeDataCiteRegistrationService (missing credentials)');
                return new FakeDataCiteRegistrationService;
            }
            
            \Illuminate\Support\Facades\Log::info('TestingServiceProvider: Using real DataCiteRegistrationService');
            return new DataCiteRegistrationService;
        });
    }

    /**
     * Determine if fake DataCite service should be used based on missing credentials
     *
     * @param \Illuminate\Foundation\Application $app
     */
    private function shouldUseFakeDataCiteService($app): bool
    {
        // Use fake service if credentials are missing (E2E test scenario)
        $testMode = (bool) $app['config']->get('datacite.test_mode', true);

        if ($testMode) {
            $username = $app['config']->get('datacite.test.username');
            $password = $app['config']->get('datacite.test.password');
        } else {
            $username = $app['config']->get('datacite.production.username');
            $password = $app['config']->get('datacite.production.password');
        }

        $shouldFake = empty($username) || empty($password);

        \Illuminate\Support\Facades\Log::info('TestingServiceProvider: Credential check', [
            'test_mode' => $testMode,
            'has_username' => !empty($username),
            'has_password' => !empty($password),
            'should_use_fake' => $shouldFake,
        ]);

        return $shouldFake;
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
