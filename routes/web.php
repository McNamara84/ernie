<?php

use App\Http\Controllers\ResourceController;
use App\Http\Controllers\OldDatasetController;
use App\Http\Controllers\UploadXmlController;
use App\Http\Controllers\VocabularyController;
use App\Models\License;
use App\Models\Resource;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'laravel' => app()->version()
    ]);
})->name('health');

Route::get('/debug', function () {
    return response()->json([
        'message' => 'Laravel is working!',
        'database' => 'Connected',
        'redis' => Cache::get('test') !== null ? 'Available' : 'Testing...',
        'app_key' => config('app.key') ? 'Set' : 'Missing',
        'app_url' => config('app.url'),
        'environment' => app()->environment()
    ]);
})->name('debug');

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

Route::middleware(['auth', 'verified'])->group(function () {
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

    // DOI validation endpoint (proxy to avoid CORS issues)
    Route::post('api/validate-doi', [App\Http\Controllers\DoiValidationController::class, 'validateDoi'])
        ->name('api.validate-doi');

    Route::get('resources', [ResourceController::class, 'index'])
        ->name('resources');

    Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])
        ->name('resources.destroy');

    Route::post('dashboard/upload-xml', UploadXmlController::class)
        ->name('dashboard.upload-xml');

    Route::get('dashboard', function () {
        return Inertia::render('dashboard', [
            'resourceCount' => Resource::count(),
        ]);
    })->name('dashboard');

    Route::get('docs', function () {
        return Inertia::render('docs');
    })->name('docs');

    Route::get('docs/users', function () {
        return Inertia::render('docs-users');
    })->name('docs.users');

    Route::get('editor', function (\Illuminate\Http\Request $request) {
        // Decode relatedWorks from JSON if it's a string (to handle large datasets)
        $relatedWorks = $request->query('relatedWorks', []);
        if (is_string($relatedWorks)) {
            $decoded = json_decode($relatedWorks, true);
            $relatedWorks = is_array($decoded) ? $decoded : [];
        }

        // Transform relatedWorks from camelCase to snake_case if needed
        // (legacy import uses camelCase, but frontend expects snake_case)
        $relatedWorks = array_map(function ($item) {
            if (isset($item['identifierType'])) {
                $item['identifier_type'] = $item['identifierType'];
                unset($item['identifierType']);
            }
            if (isset($item['relationType'])) {
                $item['relation_type'] = $item['relationType'];
                unset($item['relationType']);
            }
            return $item;
        }, $relatedWorks);

        // Get funding references from query parameters
        $fundingReferences = $request->query('fundingReferences', []);
        if (is_string($fundingReferences)) {
            $decoded = json_decode($fundingReferences, true);
            $fundingReferences = is_array($decoded) ? $decoded : [];
        }

        // Get MSL Laboratories from query parameters
        $mslLaboratories = $request->query('mslLaboratories', []);
        if (is_string($mslLaboratories)) {
            $decoded = json_decode($mslLaboratories, true);
            $mslLaboratories = is_array($decoded) ? $decoded : [];
        }

        return Inertia::render('editor', [
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'googleMapsApiKey' => config('services.google_maps.api_key'),
            'doi' => $request->query('doi'),
            'year' => $request->query('year'),
            'version' => $request->query('version'),
            'language' => $request->query('language'),
            'resourceType' => $request->query('resourceType'),
            'resourceId' => $request->query('resourceId'),
            'titles' => $request->query('titles', []),
            'initialLicenses' => $request->query('licenses', []),
            'authors' => $request->query('authors', []),
            'contributors' => $request->query('contributors', []),
            'descriptions' => $request->query('descriptions', []),
            'dates' => $request->query('dates', []),
            'gcmdKeywords' => $request->query('gcmdKeywords', []),
            'freeKeywords' => $request->query('freeKeywords', []),
            'coverages' => $request->query('coverages', []),
            'relatedWorks' => $relatedWorks,
            'fundingReferences' => $fundingReferences,
            'mslLaboratories' => $mslLaboratories,
        ]);
    })->name('editor');

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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
