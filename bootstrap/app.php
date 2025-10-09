<?php

use App\Http\Middleware\EnsureValidElmoApiKey;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use App\Console\Kernel as ConsoleKernel;

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
        ]);

        // Use custom CSRF middleware for PathPrefix compatibility
        $middleware->validateCsrfTokens(except: [
            // Add any routes that should be excluded from CSRF verification
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetUrlRoot::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
