<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure routes are only accessible in local/testing environments.
 *
 * This provides defense-in-depth for test helper routes. Even if routes are
 * accidentally cached with the wrong environment or configuration is misconfigured,
 * this middleware will block access in production.
 *
 * Use this middleware for:
 * - Playwright E2E test helper endpoints
 * - Development-only debugging routes
 * - Any routes that should never be accessible in production
 *
 * @see routes/web.php - Test helper routes
 * @see tests/playwright/helpers/page-objects/LandingPage.ts - Playwright usage
 */
class EnsureTestEnvironment
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Block access unless running in local or testing environment.
        // Use config('app.env') instead of app()->environment() for robustness:
        // - config() reads from cached config, which survives process restarts
        // - app()->environment() can be unreliable with config caching
        // This is a defense-in-depth check that protects against:
        // - Route cache containing test routes from wrong environment
        // - Misconfigured APP_ENV in production
        // - Accidental exposure through deployment errors
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            // Return 404 instead of 403 to avoid revealing that the route exists
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
