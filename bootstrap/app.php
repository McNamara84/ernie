<?php

use App\Console\Kernel as ConsoleKernel;
use App\Http\Middleware\EnsureTestEnvironment;
use App\Http\Middleware\EnsureValidElmoApiKey;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withSingletons([
        ConsoleKernelContract::class => ConsoleKernel::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'elmo.api-key' => EnsureValidElmoApiKey::class,
            'ensure.test-environment' => EnsureTestEnvironment::class,
            // Note: 'can.manage.users' middleware has been replaced by Gate-based authorization
            // Use Route::middleware(['can:access-administration']) or ['can:manage-users'] instead
        ]);

        // CSRF token validation - using standard Laravel middleware
        $middleware->validateCsrfTokens(except: [
            // Add any routes that should be excluded from CSRF verification
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
