<?php

use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LandingPagePreviewController;
use App\Http\Controllers\LandingPagePublicController;
use App\Http\Controllers\OldDatasetController;
use App\Http\Controllers\OldDataStatisticsController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\TestHelperController;
use App\Http\Controllers\UploadXmlController;
use App\Http\Controllers\VocabularyController;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'laravel' => app()->version(),
    ]);
})->name('health');

// Debug route - only available in local/testing environments
if (app()->environment('local', 'testing')) {
    Route::get('/debug', function () {
        return response()->json([
            'message' => 'Laravel is working!',
            'database' => 'Connected',
            'redis' => Cache::get('test') !== null ? 'Available' : 'Testing...',
            'app_key' => config('app.key') ? 'Set' : 'Missing',
            'app_url' => config('app.url'),
            'environment' => app()->environment(),
        ]);
    })->name('debug');
}

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('/about', function () {
    return Inertia::render('about');
})->name('about');

Route::get('/legal-notice', function () {
    return Inertia::render('legal-notice');
})->name('legal-notice');

Route::get('/changelog', function () {
    return Inertia::render('changelog');
})->name('changelog');

// Public Landing Pages (accessible without authentication)
// ===========================================================

// Landing Pages with DOI (e.g., /10.5880/test.001/my-dataset-title)
// DOI prefix format: 10.NNNN/suffix where suffix contains valid DOI characters.
// The regex pattern '10\.[0-9]+/[a-zA-Z0-9._/-]+' is intentionally permissive to
// accommodate various DOI suffix formats used by different registrants.
// Valid DOI suffixes can contain alphanumerics, dots, underscores, hyphens, and slashes.
// Example valid DOIs: 10.5880/GFZ.1.2.2024.001, 10.14470/test-dataset, 10.5880/igets.bu.l1.001
//
// Multi-segment DOI handling: Since the pattern allows '/' in the suffix, a DOI like
// "10.5880/test/with/slashes" is valid. Laravel's route matching is greedy for the
// doiPrefix parameter, consuming all path segments that match the pattern, leaving
// only the final segment as the slug. The slug pattern '[a-z0-9-]+' ensures it
// cannot contain slashes, so the slug is always unambiguous.
Route::get('{doiPrefix}/{slug}', [LandingPagePublicController::class, 'show'])
    ->name('landing-page.show')
    ->where('doiPrefix', '10\.[0-9]+/[a-zA-Z0-9._/-]+')
    ->where('slug', '[a-z0-9-]+');

Route::post('{doiPrefix}/{slug}/contact', [ContactMessageController::class, 'store'])
    ->name('landing-page.contact')
    ->where('doiPrefix', '10\.[0-9]+/[a-zA-Z0-9._/-]+')
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:10,1');

// Landing Pages without DOI (draft mode, e.g., /draft-123/my-dataset-title)
Route::get('draft-{resourceId}/{slug}', [LandingPagePublicController::class, 'showDraft'])
    ->name('landing-page.show-draft')
    ->where('resourceId', '[0-9]+')
    ->where('slug', '[a-z0-9-]+');

Route::post('draft-{resourceId}/{slug}/contact', [ContactMessageController::class, 'storeDraft'])
    ->name('landing-page.contact-draft')
    ->where('resourceId', '[0-9]+')
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:10,1');

// Legacy route for backwards compatibility during transition (can be removed later).
// Returns 404 if landing page doesn't exist - this is intentional because:
// - Legacy URLs should only redirect if the landing page was actually migrated
// - Checking for resource existence without landing page would be misleading
// - Search engines should get 404 for invalid legacy URLs, not false redirects
Route::get('datasets/{resourceId}', [LandingPagePublicController::class, 'showLegacy'])
    ->name('landing-page.show-legacy')
    ->where('resourceId', '[0-9]+');

/*
 |--------------------------------------------------------------------------
 | Test Helper Routes (Local/Testing Environment Only)
 |--------------------------------------------------------------------------
 |
 | These routes are ONLY available when APP_ENV=local or APP_ENV=testing.
 | They provide helper endpoints for Playwright E2E tests to look up landing
 | pages by slug without knowing the full semantic URL in advance.
 |
 | SECURITY: Multiple layers of protection ensure these routes never run in production:
 | 1. Route registration check: config('app.env') in ['local', 'testing']
 | 2. Middleware check: EnsureTestEnvironment middleware (survives route cache)
 | 3. Runtime check: Additional config('app.env') check inside handler
 |
 | Production deployment checklist:
 | - Verify APP_ENV=production in .env
 | - Verify APP_DEBUG=false in .env
 | - Routes should NOT appear in 'php artisan route:list' in production
 |
 | @see tests/playwright/helpers/page-objects/LandingPage.ts - goto() method
 | @see .github/workflows/playwright.yml - sets APP_ENV=testing
 */
// Use config() for more robust environment check that survives config caching.
// app()->environment() can be unreliable if APP_ENV was different when config was cached.
if (in_array(config('app.env'), ['local', 'testing'], true)) {
    Route::middleware(['ensure.test-environment', 'throttle:60,1'])->group(function () {
        // Using a dedicated controller instead of a closure allows route caching
        // and provides better consistency with the rest of the codebase.
        Route::get('_test/landing-page-by-slug/{slug}', [TestHelperController::class, 'getLandingPageBySlug'])
            ->name('test.landing-page-by-slug');
    });
}

Route::middleware(['auth', 'verified'])->group(function () {
    // Old Datasets routes (Admin only - Issue #379)
    Route::middleware(['can:access-old-datasets'])->group(function () {
        Route::get('old-datasets', [OldDatasetController::class, 'index'])
            ->name('old-datasets');

        Route::get('old-datasets/filter-options', [OldDatasetController::class, 'getFilterOptions'])
            ->name('old-datasets.filter-options');

        Route::get('old-datasets/load-more', [OldDatasetController::class, 'loadMore'])
            ->name('old-datasets.load-more');

        Route::get('old-datasets/{id}/authors', [OldDatasetController::class, 'getAuthors'])
            ->name('old-datasets.authors');

        Route::get('old-datasets/{id}/contributors', [OldDatasetController::class, 'getContributors'])
            ->name('old-datasets.contributors');

        Route::get('old-datasets/{id}/funding-references', [OldDatasetController::class, 'getFundingReferences'])
            ->name('old-datasets.funding-references');

        Route::get('old-datasets/{id}/descriptions', [OldDatasetController::class, 'getDescriptions'])
            ->name('old-datasets.descriptions');

        Route::get('old-datasets/{id}/dates', [OldDatasetController::class, 'getDates'])
            ->name('old-datasets.dates');

        Route::get('old-datasets/{id}/controlled-keywords', [OldDatasetController::class, 'getControlledKeywords'])
            ->name('old-datasets.controlled-keywords');

        Route::get('old-datasets/{id}/free-keywords', [OldDatasetController::class, 'getFreeKeywords'])
            ->name('old-datasets.free-keywords');

        Route::get('old-datasets/{id}/msl-keywords', [OldDatasetController::class, 'getMslKeywords'])
            ->name('old-datasets.msl-keywords');

        Route::get('old-datasets/{id}/coverages', [OldDatasetController::class, 'getCoverages'])
            ->name('old-datasets.coverages');

        Route::get('old-datasets/{id}/related-identifiers', [OldDatasetController::class, 'getRelatedIdentifiers'])
            ->name('old-datasets.related-identifiers');

        Route::get('old-datasets/{id}/msl-laboratories', [OldDatasetController::class, 'getMslLaboratories'])
            ->name('old-datasets.msl-laboratories');
    });

    // Statistics routes (Admin, Group Leader - Issue #379)
    Route::middleware(['can:access-statistics'])->group(function () {
        Route::get('old-statistics', [OldDataStatisticsController::class, 'index'])
            ->name('old-statistics');
    });

    // Logs routes (Admin only - Issue #379)
    Route::middleware(['can:access-logs'])->group(function () {
        Route::get('logs', [\App\Http\Controllers\LogController::class, 'index'])
            ->name('logs.index');

        Route::get('logs/data', [\App\Http\Controllers\LogController::class, 'getLogsJson'])
            ->name('logs.data');

        Route::delete('logs/entry', [\App\Http\Controllers\LogController::class, 'destroy'])
            ->middleware('can:delete-logs')
            ->name('logs.destroy');

        Route::delete('logs/clear', [\App\Http\Controllers\LogController::class, 'clear'])
            ->middleware('can:delete-logs')
            ->name('logs.clear');
    });

    // Thesaurus settings routes (Admin only)
    Route::middleware(['can:manage-thesauri'])->prefix('thesauri')->group(function () {
        Route::get('/', [\App\Http\Controllers\Settings\ThesaurusSettingsController::class, 'index'])
            ->name('thesauri.index');
        Route::post('/{type}/check', [\App\Http\Controllers\Settings\ThesaurusSettingsController::class, 'checkStatus'])
            ->name('thesauri.check');
        Route::post('/{type}/update', [\App\Http\Controllers\Settings\ThesaurusSettingsController::class, 'triggerUpdate'])
            ->name('thesauri.update');
        Route::get('/update-status/{jobId}', [\App\Http\Controllers\Settings\ThesaurusSettingsController::class, 'updateStatus'])
            ->name('thesauri.update-status');
    });

    // Resources routes (new curated resources)
    Route::get('resources/filter-options', [ResourceController::class, 'getFilterOptions'])
        ->name('resources.filter-options');

    Route::get('resources/load-more', [ResourceController::class, 'loadMore'])
        ->name('resources.load-more');

    Route::get('resources/{resource}/export-datacite-json', [ResourceController::class, 'exportDataCiteJson'])
        ->name('resources.export-datacite-json');

    Route::get('resources/{resource}/export-datacite-xml', [ResourceController::class, 'exportDataCiteXml'])
        ->name('resources.export-datacite-xml');

    Route::post('resources/{resource}/register-doi', [ResourceController::class, 'registerDoi'])
        ->name('resources.register-doi');

    // DataCite prefix configuration endpoint
    Route::get('api/datacite/prefixes', [ResourceController::class, 'getDataCitePrefixes'])
        ->name('api.datacite.prefixes');

    // DOI validation endpoint (proxy to avoid CORS issues)
    Route::post('api/validate-doi', [App\Http\Controllers\DoiValidationController::class, 'validateDoi'])
        ->name('api.validate-doi');

    Route::get('resources', [ResourceController::class, 'index'])
        ->name('resources');

    Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])
        ->name('resources.destroy');

    // DataCite Import (Admin/Group Leader only)
    Route::post('datacite/import/start', [App\Http\Controllers\DataCiteImportController::class, 'start'])
        ->name('datacite.import.start');

    Route::get('datacite/import/{importId}/status', [App\Http\Controllers\DataCiteImportController::class, 'status'])
        ->name('datacite.import.status');

    Route::post('datacite/import/{importId}/cancel', [App\Http\Controllers\DataCiteImportController::class, 'cancel'])
        ->name('datacite.import.cancel');

    // Landing Page Management (Admin)
    Route::post('resources/{resource}/landing-page', [LandingPageController::class, 'store'])
        ->name('landing-page.store');

    Route::put('resources/{resource}/landing-page', [LandingPageController::class, 'update'])
        ->name('landing-page.update');

    Route::delete('resources/{resource}/landing-page', [LandingPageController::class, 'destroy'])
        ->name('landing-page.destroy');

    Route::get('resources/{resource}/landing-page', [LandingPageController::class, 'get'])
        ->name('landing-page.get');

    // Landing Page Temporary Preview (Session-based)
    Route::post('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'store'])
        ->name('landing-page.preview.store');

    Route::get('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'show'])
        ->name('landing-page.preview.show');

    Route::delete('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'destroy'])
        ->name('landing-page.preview.destroy');

    Route::post('dashboard/upload-xml', UploadXmlController::class)
        ->name('dashboard.upload-xml');

    Route::get('dashboard', function () {
        return Inertia::render('dashboard', [
            'resourceCount' => Resource::count(),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
        ]);
    })->name('dashboard');

    Route::get('docs', function () {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return Inertia::render('docs', [
            'userRole' => $user->role->value,
        ]);
    })->name('docs');

    Route::get('editor', [EditorController::class, 'show'])->name('editor');

    Route::post('editor/resources', [ResourceController::class, 'store'])
        ->name('editor.resources.store');

    // GCMD Vocabulary routes for frontend (without API key requirement)
    Route::get('vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords'])
        ->name('vocabularies.gcmd-science-keywords');
    Route::get('vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms'])
        ->name('vocabularies.gcmd-platforms');
    Route::get('vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments'])
        ->name('vocabularies.gcmd-instruments');
    Route::get('vocabularies/msl', [VocabularyController::class, 'mslVocabulary'])
        ->name('vocabularies.msl');
    Route::get('vocabularies/msl-vocabulary-url', function () {
        return response()->json([
            'url' => config('msl.vocabulary_url'),
        ]);
    })->name('vocabularies.msl-vocabulary-url');

    // User Management routes (Admin & Group Leader only - Issue #379)
    Route::middleware(['can:access-users'])->prefix('users')->group(function () {
        Route::get('/', [App\Http\Controllers\UserController::class, 'index'])
            ->name('users.index');
        Route::post('/', [App\Http\Controllers\UserController::class, 'store'])
            ->name('users.store');
        Route::patch('{user}/role', [App\Http\Controllers\UserController::class, 'updateRole'])
            ->name('users.update-role');
        Route::post('{user}/deactivate', [App\Http\Controllers\UserController::class, 'deactivate'])
            ->name('users.deactivate');
        Route::post('{user}/reactivate', [App\Http\Controllers\UserController::class, 'reactivate'])
            ->name('users.reactivate');
        Route::post('{user}/reset-password', [App\Http\Controllers\UserController::class, 'resetPassword'])
            ->name('users.reset-password');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
