<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\AssistanceController;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the modular assistant system.
 *
 * Registers the AssistantRegistrar singleton, discovers modules at boot,
 * and registers dynamic routes for each discovered assistant.
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssistantRegistrar::class, function () {
            return new AssistantRegistrar();
        });
    }

    public function boot(): void
    {
        /** @var AssistantRegistrar $registrar */
        $registrar = $this->app->make(AssistantRegistrar::class);

        $basePath = base_path('modules/assistants');
        $registrar->discoverModules($basePath);

        $this->registerRoutes($registrar);
    }

    /**
     * Register dynamic routes for all discovered assistant modules.
     */
    private function registerRoutes(AssistantRegistrar $registrar): void
    {
        Route::middleware(['web', 'auth', 'verified', 'can:access-assistance'])
            ->prefix('assistance')
            ->group(function () use ($registrar) {
                // Index page — always present
                Route::get('/', [AssistanceController::class, 'index'])
                    ->name('assistance');

                // Check all — always present
                Route::post('/check-all', [AssistanceController::class, 'checkAll'])
                    ->name('assistance.check-all');

                // Dynamic routes for each registered assistant
                foreach ($registrar->getAll() as $assistant) {
                    $prefix = $assistant->getManifest()->routePrefix;
                    $id = $assistant->getId();

                    // Start discovery
                    Route::post("/check/{$id}", [AssistanceController::class, 'check'])
                        ->name("assistance.check.{$id}")
                        ->defaults('assistantId', $id);

                    // Poll job status
                    Route::get("/check/{$id}/{jobId}/status", [AssistanceController::class, 'status'])
                        ->where('jobId', '[a-f0-9-]{36}')
                        ->name("assistance.check.{$id}.status")
                        ->defaults('assistantId', $id);

                    // Accept suggestion
                    Route::post("/{$prefix}/{suggestion}/accept", [AssistanceController::class, 'accept'])
                        ->where('suggestion', '[0-9]+')
                        ->name("assistance.{$id}.accept")
                        ->defaults('assistantId', $id);

                    // Decline suggestion
                    Route::post("/{$prefix}/{suggestion}/decline", [AssistanceController::class, 'decline'])
                        ->where('suggestion', '[0-9]+')
                        ->name("assistance.{$id}.decline")
                        ->defaults('assistantId', $id);
                }
            });
    }
}
