<?php

use App\Http\Controllers\Api\CitationLookupController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\BatchIgsnController;
use App\Http\Controllers\BatchIgsnRegistrationController;
use App\Http\Controllers\BatchResourceExportController;
use App\Http\Controllers\BatchResourceRegistrationController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\DatabaseDumpController;
use App\Http\Controllers\DatacenterController;
use App\Http\Controllers\DataCiteImportController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\DoiValidationController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\GuidedTourAssignmentController;
use App\Http\Controllers\IgsnController;
use App\Http\Controllers\IgsnImportController;
use App\Http\Controllers\IgsnMapController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LandingPageDomainController;
use App\Http\Controllers\LandingPageDownloadRedirectController;
use App\Http\Controllers\LandingPagePreviewController;
use App\Http\Controllers\LandingPagePublicController;
use App\Http\Controllers\LandingPageTemplateController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OaiPmh\OaiPmhController;
use App\Http\Controllers\OaiPmh\OaiPmhDocsController;
use App\Http\Controllers\OldDatasetController;
use App\Http\Controllers\OldDataStatisticsController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalSearchAnalyticsController;
use App\Http\Controllers\RelatedItemController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ResourceDoiRegistrationController;
use App\Http\Controllers\ResourceExportController;
use App\Http\Controllers\ResourceFilterController;
use App\Http\Controllers\Settings\PidSettingsController;
use App\Http\Controllers\Settings\ThesaurusSettingsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TestHelperController;
use App\Http\Controllers\UploadIgsnCsvController;
use App\Http\Controllers\UploadJsonController;
use App\Http\Controllers\UploadXmlController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VocabularyController;
use App\Models\Affiliation;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\User;
use App\Services\GuidedTours\GuidedTourAssignmentService;
use App\Services\ResourceCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
})->name('health');

// Sanctum-compatible CSRF cookie endpoint (/sanctum/csrf-cookie).
// Sanctum itself is not installed – this lightweight route provides the
// same contract: the PreventRequestForgery middleware automatically sets the
// XSRF-TOKEN cookie on every response, so this endpoint only needs to
// return 204 No Content.
// Used by the session warmup hook and the 419 CSRF retry handler.
Route::get('/sanctum/csrf-cookie', fn () => response()->noContent())
    ->name('csrf-cookie');

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

// Public Portal (Dataset Discovery)
// ===========================================================
Route::get('/portal', [PortalController::class, 'index'])
    ->middleware('throttle:public-portal')
    ->name('portal');

Route::post('/portal/search-analytics', [PortalSearchAnalyticsController::class, 'store'])
    ->middleware('throttle:public-portal')
    ->name('portal.search-analytics');

// OAI-PMH Harvesting Endpoint
// ===========================================================
Route::get('/oai-pmh/docs', [OaiPmhDocsController::class, 'index'])->name('oaipmh.docs');
Route::match(['get', 'post'], '/oai-pmh', OaiPmhController::class)->middleware('throttle:oai-pmh')->name('oaipmh');

// Public Landing Pages (accessible without authentication)
// ===========================================================

Route::get('landing-page-downloads/{landingPage}/primary', [LandingPageDownloadRedirectController::class, 'primary'])
    ->middleware('throttle:public-landing-page')
    ->name('landing-page.download.primary')
    ->whereNumber('landingPage');

Route::get('landing-page-downloads/{landingPage}/files/{landingPageFile}', [LandingPageDownloadRedirectController::class, 'file'])
    ->middleware('throttle:public-landing-page')
    ->name('landing-page.download.file')
    ->whereNumber('landingPage')
    ->whereNumber('landingPageFile');

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

// JSON-LD download for published landing pages (must be defined BEFORE the catch-all show route)
Route::get('{doiPrefix}/{slug}/jsonld', [LandingPagePublicController::class, 'exportJsonLd'])
    ->middleware('throttle:public-landing-jsonld')
    ->name('landing-page.export-jsonld')
    ->where('doiPrefix', '10\.[0-9]+/[a-zA-Z0-9._/-]+')
    ->where('slug', '[a-z0-9-]+');

Route::get('{doiPrefix}/{slug}', [LandingPagePublicController::class, 'show'])
    ->middleware('throttle:public-landing-page')
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
    ->middleware('throttle:public-landing-page')
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
    // Assistance routes are registered dynamically by AssistantServiceProvider.
    // @see \App\Providers\AssistantServiceProvider::registerRoutes()

    Route::middleware(['can:access-assessment'])->group(function () {
        Route::get('assessment', [AssessmentController::class, 'index'])
            ->name('assessment');

        Route::post('assessment/check-all', [AssessmentController::class, 'checkAll'])
            ->name('assessment.check-all');

        Route::post('assessment/check-resources', [AssessmentController::class, 'checkResources'])
            ->name('assessment.check-resources');

        Route::post('assessment/check-igsns', [AssessmentController::class, 'checkIgsns'])
            ->name('assessment.check-igsns');

        Route::get('assessment/check/{scope}/{jobId}/status', [AssessmentController::class, 'status'])
            ->where('scope', 'resource|igsn')
            ->where('jobId', '[a-f0-9-]{36}')
            ->name('assessment.status');
    });

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
        Route::get('statistics', [StatisticsController::class, 'index'])
            ->name('statistics');

        Route::get('old-statistics', [OldDataStatisticsController::class, 'index'])
            ->name('old-statistics');
    });

    // Logs routes (Admin only - Issue #379)
    Route::middleware(['can:access-logs'])->group(function () {
        Route::get('logs', [LogController::class, 'index'])
            ->name('logs.index');

        Route::get('logs/data', [LogController::class, 'getLogsJson'])
            ->name('logs.data');

        Route::delete('logs/entry', [LogController::class, 'destroy'])
            ->middleware('can:delete-logs')
            ->name('logs.destroy');

        Route::delete('logs/clear', [LogController::class, 'clear'])
            ->middleware('can:delete-logs')
            ->name('logs.clear');
    });

    // Database dump routes (Admin only)
    Route::middleware(['can:access-database-dumps'])->group(function () {
        Route::get('database', [DatabaseDumpController::class, 'index'])
            ->name('database.index');

        Route::post('database/{target}/dumps', [DatabaseDumpController::class, 'store'])
            ->name('database.dumps.store');

        Route::get('database/dumps/{export}/status', [DatabaseDumpController::class, 'status'])
            ->name('database.dumps.status');

        Route::get('database/dumps/{export}/download', [DatabaseDumpController::class, 'download'])
            ->name('database.dumps.download');
    });
    // Thesaurus settings routes (Admin and Group Leader)
    Route::middleware(['can:manage-thesauri'])->prefix('thesauri')->group(function () {
        Route::get('/', [ThesaurusSettingsController::class, 'index'])
            ->name('thesauri.index');
        Route::post('/{type}/check', [ThesaurusSettingsController::class, 'checkStatus'])
            ->name('thesauri.check');
        Route::post('/{type}/update', [ThesaurusSettingsController::class, 'triggerUpdate'])
            ->name('thesauri.update');
        Route::get('/update-status/{jobId}', [ThesaurusSettingsController::class, 'updateStatus'])
            ->name('thesauri.update-status');
        Route::patch('/{type}/version', [ThesaurusSettingsController::class, 'updateVersion'])
            ->name('thesauri.update-version');
    });

    // PID settings routes (Admin and Group Leader) - PID4INST instrument registry
    Route::middleware(['can:manage-thesauri'])->prefix('pid-settings')->group(function () {
        Route::post('/{type}/check', [PidSettingsController::class, 'checkStatus'])
            ->name('pid-settings.check');
        Route::post('/{type}/update', [PidSettingsController::class, 'triggerUpdate'])
            ->name('pid-settings.update');
        Route::get('/update-status/{jobId}', [PidSettingsController::class, 'updateStatus'])
            ->name('pid-settings.update-status');
    });

    // Resources routes (new curated resources)
    Route::get('resources/filter-options', [ResourceFilterController::class, 'getFilterOptions'])
        ->name('resources.filter-options');

    Route::get('resources/load-more', [ResourceFilterController::class, 'loadMore'])
        ->name('resources.load-more');

    Route::get('resources/{resource}/export-datacite-json', [ResourceExportController::class, 'exportDataCiteJson'])
        ->name('resources.export-datacite-json');

    Route::get('resources/{resource}/export-datacite-xml', [ResourceExportController::class, 'exportDataCiteXml'])
        ->name('resources.export-datacite-xml');

    Route::get('resources/{resource}/export-jsonld', [ResourceExportController::class, 'exportJsonLd'])
        ->name('resources.export-jsonld');

    Route::post('resources/{resource}/register-doi', [ResourceDoiRegistrationController::class, 'registerDoi'])
        ->name('resources.register-doi');

    // Related Items (DataCite 4.7) — Related Item Manager
    Route::get('related-items/vocabularies', [RelatedItemController::class, 'vocabularies'])
        ->name('related-items.vocabularies');
    Route::get('resources/{resource}/related-items', [RelatedItemController::class, 'index'])
        ->name('resources.related-items.index');
    Route::post('resources/{resource}/related-items', [RelatedItemController::class, 'store'])
        ->name('resources.related-items.store');
    Route::put('resources/{resource}/related-items/{relatedItem}', [RelatedItemController::class, 'update'])
        ->name('resources.related-items.update');
    Route::delete('resources/{resource}/related-items/{relatedItem}', [RelatedItemController::class, 'destroy'])
        ->name('resources.related-items.destroy');
    Route::post('resources/{resource}/related-items/reorder', [RelatedItemController::class, 'reorder'])
        ->name('resources.related-items.reorder');

    // Related Item Manager DOI auto-fill lookup (Crossref → DataCite fallback)
    Route::get('api/v1/citation-lookup', [CitationLookupController::class, 'lookup'])
        ->middleware('throttle:30,1')
        ->name('api.citation-lookup');

    Route::post('resources/batch-register', [BatchResourceRegistrationController::class, 'register'])
        ->name('resources.batch-register');

    Route::post('resources/batch-export', [BatchResourceExportController::class, 'export'])
        ->name('resources.batch-export');

    // DataCite prefix configuration endpoint
    Route::get('api/datacite/prefixes', [ResourceDoiRegistrationController::class, 'getDataCitePrefixes'])
        ->name('api.datacite.prefixes');

    // DOI validation endpoint (proxy to avoid CORS issues)
    Route::post('api/validate-doi', [DoiValidationController::class, 'validateDoi'])
        ->name('api.validate-doi');

    // DOI duplicate check endpoint - used by the editor form to validate DOI uniqueness
    // Rate limited to 60 requests per minute per user to prevent abuse
    Route::post('api/v1/doi/validate', [App\Http\Controllers\Api\DoiValidationController::class, 'validate'])
        ->middleware('throttle:doi-validation')
        ->name('api.doi.validate');

    Route::get('resources', [ResourceController::class, 'index'])
        ->name('resources');

    Route::delete('resources/all', [ResourceController::class, 'destroyAll'])
        ->middleware('can:delete-all-resources')
        ->name('resources.destroy-all');

    Route::delete('resources/batch', [ResourceController::class, 'destroyBatch'])
        ->name('resources.batch-destroy');

    Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])
        ->name('resources.destroy');

    // DataCite Import (Admin/Group Leader only)
    Route::get('datacite/import/datacenters', [DataCiteImportController::class, 'datacenters'])
        ->name('datacite.import.datacenters');

    Route::post('datacite/import/start', [DataCiteImportController::class, 'start'])
        ->name('datacite.import.start');

    Route::post('datacite/import/start-single', [DataCiteImportController::class, 'startSingle'])
        ->name('datacite.import.start-single');

    Route::post('datacite/import/start-datacenter', [DataCiteImportController::class, 'startDatacenter'])
        ->name('datacite.import.start-datacenter');

    Route::get('datacite/import/{importId}/status', [DataCiteImportController::class, 'status'])
        ->name('datacite.import.status');

    Route::post('datacite/import/{importId}/cancel', [DataCiteImportController::class, 'cancel'])
        ->name('datacite.import.cancel');

    // Landing Page Management (Admin)
    Route::post('resources/{resource}/landing-page', [LandingPageController::class, 'store'])
        ->name('landing-page.store');

    // Landing Page Template Management (Admin, Group Leader)
    Route::middleware(['can:manage-landing-page-templates'])->group(function () {
        Route::get('landing-pages', [LandingPageTemplateController::class, 'index'])
            ->name('landing-page-templates.index');
        Route::post('landing-pages', [LandingPageTemplateController::class, 'store'])
            ->name('landing-page-templates.store');
        Route::put('landing-pages/{landingPageTemplate}', [LandingPageTemplateController::class, 'update'])
            ->name('landing-page-templates.update');
        Route::delete('landing-pages/{landingPageTemplate}', [LandingPageTemplateController::class, 'destroy'])
            ->name('landing-page-templates.destroy');
        Route::post('landing-pages/{landingPageTemplate}/logo', [LandingPageTemplateController::class, 'uploadLogo'])
            ->name('landing-page-templates.upload-logo');
        Route::delete('landing-pages/{landingPageTemplate}/logo', [LandingPageTemplateController::class, 'deleteLogo'])
            ->name('landing-page-templates.delete-logo');
    });

    // Landing Page Templates API - accessible to all authenticated users (for template dropdown)
    Route::get('api/landing-page-templates', [LandingPageTemplateController::class, 'list'])
        ->name('landing-page-templates.list');

    Route::put('resources/{resource}/landing-page', [LandingPageController::class, 'update'])
        ->name('landing-page.update');

    Route::delete('resources/{resource}/landing-page', [LandingPageController::class, 'destroy'])
        ->name('landing-page.destroy');

    Route::get('resources/{resource}/landing-page', [LandingPageController::class, 'get'])
        ->name('landing-page.get');

    // Landing Page Domains - read-only for all authenticated users (curators need this for the modal dropdown)
    Route::get('api/landing-page-domains/list', [LandingPageDomainController::class, 'index'])
        ->name('landing-page-domains.list');

    // Download URL suggestions - read-only for all authenticated users (curators need this for the modal autocomplete)
    Route::get('api/landing-page-download-url-suggestions', [LandingPageController::class, 'downloadUrlSuggestions'])
        ->name('landing-page-download-url-suggestions.index');

    // Datacenters - read-only for all authenticated users (editor dropdown)
    Route::get('api/datacenters', [DatacenterController::class, 'index'])
        ->name('datacenters.index');

    // Landing Page Temporary Preview (Session-based)
    Route::post('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'store'])
        ->name('landing-page.preview.store');

    Route::get('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'show'])
        ->name('landing-page.preview.show');

    Route::delete('resources/{resource}/landing-page/preview', [LandingPagePreviewController::class, 'destroy'])
        ->name('landing-page.preview.destroy');

    Route::post('dashboard/upload-xml', UploadXmlController::class)
        ->name('dashboard.upload-xml');

    Route::post('dashboard/upload-json', UploadJsonController::class)
        ->name('dashboard.upload-json');

    Route::post('dashboard/upload-igsn-csv', UploadIgsnCsvController::class)
        ->name('dashboard.upload-igsn-csv');

    // IGSNs (Physical Samples) routes
    Route::get('igsns', [IgsnController::class, 'index'])
        ->name('igsns.index');
    Route::get('igsns/filter-options', [IgsnController::class, 'filterOptions'])
        ->name('igsns.filter-options');
    Route::get('igsns-map', [IgsnMapController::class, 'index'])
        ->name('igsns.map');
    // IGSN Import from DataCite
    Route::post('igsns/import/start', [IgsnImportController::class, 'start'])
        ->name('igsns.import.start');
    Route::post('igsns/import/start-single', [IgsnImportController::class, 'startSingle'])
        ->name('igsns.import.start-single');
    Route::get('igsns/import/{importId}/status', [IgsnImportController::class, 'status'])
        ->name('igsns.import.status');
    Route::post('igsns/import/{importId}/cancel', [IgsnImportController::class, 'cancel'])
        ->name('igsns.import.cancel');
    // Batch operations must be defined before routes with {resource} parameter
    Route::delete('igsns/batch', [BatchIgsnController::class, 'destroy'])
        ->name('igsns.batch.destroy');
    Route::post('igsns/batch-register', [BatchIgsnRegistrationController::class, 'register'])
        ->name('igsns.batch-register');
    Route::get('igsns/{resource}/export/json', [IgsnController::class, 'exportJson'])
        ->name('igsns.export.json');
    Route::get('igsns/{resource}/export/jsonld', [IgsnController::class, 'exportJsonLd'])
        ->name('igsns.export.jsonld');
    Route::post('igsns/{resource}/register', [IgsnController::class, 'registerAtDataCite'])
        ->name('igsns.register');
    Route::delete('igsns/{resource}', [IgsnController::class, 'destroy'])
        ->name('igsns.destroy');

    Route::get('/dashboard', function (Request $request, GuidedTourAssignmentService $guidedTourAssignmentService) {
        /** @var User $user */
        $user = $request->user();

        $guidedTour = $guidedTourAssignmentService->buildAutostartPayloadForRoute(
            user: $user,
            routeName: 'dashboard',
            shouldAutostart: (bool) $request->session()->get('guided_tours.autostart_after_login', false),
        );

        $physicalObjectTypeId = app(ResourceCacheService::class)->getPhysicalObjectTypeId();

        $applyNonIgsnResourceFilter = static function ($query) use ($physicalObjectTypeId): void {
            if ($physicalObjectTypeId === null) {
                return;
            }

            $query->where(function ($subQ) use ($physicalObjectTypeId) {
                $subQ->whereNull('resource_type_id')
                    ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
            });
        };

        // Count unique institutions (ROR-identified) for Data Resources
        $dataInstitutionCount = Affiliation::query()
            ->whereNotNull('identifier')
            ->where('identifier_scheme', 'ROR')
            ->whereHasMorph('affiliatable', [ResourceCreator::class], function ($query) use ($applyNonIgsnResourceFilter) {
                $query->whereHas('resource', function ($q) use ($applyNonIgsnResourceFilter) {
                    $applyNonIgsnResourceFilter($q);
                });
            })
            ->distinct('identifier')
            ->count('identifier');

        // Count unique institutions (ROR-identified) for IGSN Resources
        $igsnInstitutionCount = $physicalObjectTypeId
            ? Affiliation::query()
                ->whereNotNull('identifier')
                ->where('identifier_scheme', 'ROR')
                ->whereHasMorph('affiliatable', [ResourceCreator::class], function ($query) use ($physicalObjectTypeId) {
                    $query->whereHas('resource', function ($q) use ($physicalObjectTypeId) {
                        $q->where('resource_type_id', $physicalObjectTypeId);
                    });
                })
                ->distinct('identifier')
                ->count('identifier')
            : 0;

        // Draft resources: incomplete non-IGSN resources (Issue #548)
        // A resource is a draft if it lacks any of: Main Title, publication_year,
        // resource_type_id, at least one creator, at least one license, or an abstract.
        $draftQuery = Resource::query();

        $applyNonIgsnResourceFilter($draftQuery);

        $draftQuery->where(function ($q) {
            $q->whereNull('publication_year')
                ->orWhereNull('resource_type_id')
                ->orWhereDoesntHave('creators')
                ->orWhereDoesntHave('rights')
                ->orWhere(function ($titleQ) {
                    // No Main Title with non-empty trimmed value
                    // (legacy: NULL title_type_id counts as MainTitle)
                    $titleQ->whereDoesntHave('titles', function ($tq) {
                        $tq->whereRaw("TRIM(value) != ''")
                            ->where(function ($typeQ) {
                                $typeQ->whereNull('title_type_id')
                                    ->orWhereHas('titleType', fn ($tt) => $tt->where('slug', 'MainTitle'));
                            });
                    });
                })
                ->orWhereDoesntHave('descriptions', function ($dq) {
                    $dq->whereRaw("TRIM(value) != ''")
                        ->whereHas('descriptionType', fn ($dt) => $dt->where('slug', 'Abstract'));
                });
        });

        $draftCount = $draftQuery->count();

        $recentResourceQuery = Resource::query();

        $applyNonIgsnResourceFilter($recentResourceQuery);

        $recentResources = $recentResourceQuery
            ->with([
                'titles.titleType',
                'creators',
                'rights',
                'descriptions.descriptionType',
                'landingPage',
            ])
            ->where(function ($q) use ($user) {
                $q->where('updated_by_user_id', $user->id)
                    ->orWhere(function ($createdQ) use ($user) {
                        $createdQ->whereNull('updated_by_user_id')
                            ->where('created_by_user_id', $user->id);
                    });
            })
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get()
            ->map(fn (Resource $r) => [
                'id' => $r->id,
                'title' => $r->mainTitle ?? 'Untitled Resource',
                'updated_at' => $r->updated_at?->toISOString(),
                'status' => $r->publicStatus(),
            ])
            ->all();

        return Inertia::render('dashboard', [
            'dataInstitutionCount' => $dataInstitutionCount,
            'igsnInstitutionCount' => $igsnInstitutionCount,
            'draftCount' => $draftCount,
            'recentResources' => $recentResources,
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'guidedTour' => $guidedTour,
        ]);
    })->name('dashboard');

    Route::get('docs', [DocsController::class, 'show'])->name('docs');

    Route::get('editor', [EditorController::class, 'show'])->name('editor');

    Route::post('editor/resources', [ResourceController::class, 'store'])
        ->name('editor.resources.store');

    Route::post('editor/resources/draft', [ResourceController::class, 'storeDraft'])
        ->name('editor.resources.store-draft');

    // GCMD Vocabulary routes for frontend (without API key requirement)
    Route::get('vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords'])
        ->name('vocabularies.gcmd-science-keywords');
    Route::get('vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms'])
        ->name('vocabularies.gcmd-platforms');
    Route::get('vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments'])
        ->name('vocabularies.gcmd-instruments');
    Route::get('vocabularies/msl', [VocabularyController::class, 'mslVocabulary'])
        ->name('vocabularies.msl');
    Route::get('vocabularies/pid4inst-instruments', [VocabularyController::class, 'pid4instInstruments'])
        ->name('vocabularies.pid4inst-instruments');
    Route::get('vocabularies/raid-projects', [VocabularyController::class, 'raidProjects'])
        ->name('vocabularies.raid-projects');
    Route::get('vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale'])
        ->name('vocabularies.chronostrat-timescale');
    Route::get('vocabularies/gemet', [VocabularyController::class, 'gemetThesaurus'])
        ->name('vocabularies.gemet');
    Route::get('vocabularies/analytical-methods', [VocabularyController::class, 'analyticalMethods'])
        ->name('vocabularies.analytical-methods');
    Route::get('vocabularies/euroscivoc', [VocabularyController::class, 'euroSciVoc'])
        ->name('vocabularies.euroscivoc');
    Route::get('vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability'])
        ->name('vocabularies.pid-availability');
    Route::get('vocabularies/msl-vocabulary-url', function () {
        return response()->json([
            'url' => config('msl.vocabulary_url'),
        ]);
    })->name('vocabularies.msl-vocabulary-url');

    // User Management routes (Admin & Group Leader only - Issue #379)
    Route::middleware(['can:access-users'])->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])
            ->name('users.index');
        Route::post('/', [UserController::class, 'store'])
            ->name('users.store');
        Route::patch('{user}/role', [UserController::class, 'updateRole'])
            ->name('users.update-role');
        Route::post('{user}/deactivate', [UserController::class, 'deactivate'])
            ->name('users.deactivate');
        Route::post('{user}/reactivate', [UserController::class, 'reactivate'])
            ->name('users.reactivate');
        Route::post('{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('users.reset-password');
        Route::post('{user}/guided-tours', [UserController::class, 'assignGuidedTours'])
            ->name('users.assign-guided-tours');
    });

    Route::prefix('guided-tours/assignments')->group(function () {
        Route::post('{assignment}/start', [GuidedTourAssignmentController::class, 'start'])
            ->name('guided-tours.assignments.start');
        Route::post('{assignment}/close', [GuidedTourAssignmentController::class, 'close'])
            ->name('guided-tours.assignments.close');
        Route::post('{assignment}/complete', [GuidedTourAssignmentController::class, 'complete'])
            ->name('guided-tours.assignments.complete');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
