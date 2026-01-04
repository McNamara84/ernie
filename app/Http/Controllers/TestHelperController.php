<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test Helper Controller
 *
 * Provides helper endpoints for Playwright E2E tests.
 * These endpoints are ONLY available in local/testing environments.
 *
 * SECURITY: Multiple layers of protection ensure these routes never run in production:
 * 1. Route registration check: config('app.env') in ['local', 'testing']
 * 2. Middleware check: EnsureTestEnvironment middleware
 * 3. Runtime check: Additional config() check inside each method
 *
 * @see routes/web.php for route registration
 * @see tests/playwright/helpers/page-objects/LandingPage.ts for usage
 */
class TestHelperController extends Controller
{
    /**
     * Look up a landing page by its slug.
     *
     * Returns the landing page's URLs for Playwright tests to navigate
     * to semantic URLs without knowing the full DOI path in advance.
     *
     * If multiple landing pages share the same slug (possible with drafts),
     * returns the most recently created one.
     *
     * @param  string  $slug  The URL slug to look up
     */
    public function getLandingPageBySlug(string $slug): JsonResponse
    {
        // Defense-in-depth: Additional runtime check for test environment.
        // This is the third layer of protection after route registration and middleware.
        //
        // We use AND logic (both checks must pass) to prevent security bypass scenarios:
        // - If config cache is stale with APP_ENV=testing but actual env is production,
        //   the app()->environment() check will fail, blocking access
        // - If .env is somehow misconfigured but config cache is correct (production),
        //   the config check will fail, blocking access
        // Using AND logic is more restrictive and safer than OR logic.
        $configEnv = config('app.env');
        $appEnv = app()->environment();

        $configIsTestEnv = in_array($configEnv, ['local', 'testing'], true);
        $appIsTestEnv = in_array($appEnv, ['local', 'testing'], true);

        // BOTH must be test environments - if either is production, deny access
        if (! $configIsTestEnv || ! $appIsTestEnv) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Use latest() to get the most recently created landing page if multiple
        // share the same slug (can happen with draft pages).
        $landingPage = LandingPage::where('slug', $slug)
            ->latest('id')
            ->first();

        if (! $landingPage) {
            return response()->json([
                'error' => 'Landing page not found',
                'hint' => 'Make sure test data is seeded (run: php artisan db:seed --class=PlaywrightTestSeeder)',
            ], Response::HTTP_NOT_FOUND);
        }

        // Use the model's getPublicPath() method to ensure URL format consistency.
        // This method returns relative paths (without host/scheme), which is what
        // Playwright needs since it uses baseURL from its config.
        $publicPath = $landingPage->getPublicPath();

        $previewPath = $landingPage->preview_token !== null
            ? "{$publicPath}?preview={$landingPage->preview_token}"
            : null;

        return response()->json([
            'public_url' => $publicPath,
            'preview_url' => $previewPath,
            'doi_prefix' => $landingPage->doi_prefix,
            'slug' => $landingPage->slug,
        ]);
    }
}
