<?php

use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LandingPagePreviewController;
use App\Http\Controllers\LandingPagePublicController;
use App\Http\Controllers\OldDatasetController;
use App\Http\Controllers\OldDataStatisticsController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\UploadXmlController;
use App\Http\Controllers\VocabularyController;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\Setting;
use App\Services\OldDatasetEditorLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'laravel' => app()->version(),
    ]);
})->name('health');

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
// DOI prefix starts with 10. and can contain slashes
Route::get('{doiPrefix}/{slug}', [LandingPagePublicController::class, 'show'])
    ->name('landing-page.show')
    ->where('doiPrefix', '10\.[0-9]+/.+')
    ->where('slug', '[a-z0-9-]+');

Route::post('{doiPrefix}/{slug}/contact', [ContactMessageController::class, 'store'])
    ->name('landing-page.contact')
    ->where('doiPrefix', '10\.[0-9]+/.+')
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

// Legacy route for backwards compatibility during transition (can be removed later)
Route::get('datasets/{resourceId}', [LandingPagePublicController::class, 'showLegacy'])
    ->name('landing-page.show-legacy')
    ->where('resourceId', '[0-9]+');

// Test helper route: Lookup landing page URL by slug (for Playwright E2E tests)
// Returns JSON with the public_url for a landing page with the given slug
if (app()->environment('local', 'testing')) {
    Route::get('_test/landing-page-by-slug/{slug}', function (string $slug) {
        $landingPage = \App\Models\LandingPage::where('slug', $slug)->first();
        if (! $landingPage) {
            return response()->json(['error' => 'Landing page not found'], 404);
        }

        return response()->json([
            'public_url' => $landingPage->public_url,
            'preview_url' => $landingPage->preview_url,
            'doi_prefix' => $landingPage->doi_prefix,
            'slug' => $landingPage->slug,
        ]);
    })->name('test.landing-page-by-slug');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('old-datasets', [OldDatasetController::class, 'index'])
        ->name('old-datasets');

    Route::get('old-statistics', [OldDataStatisticsController::class, 'index'])
        ->name('old-statistics');

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

    Route::get('editor', function (\Illuminate\Http\Request $request) {
        // Define author/contributor exclusion roles
        $excludedAuthorRoles = ['author', 'contact-person'];

        // Check if we're loading an existing resource from new database
        $resourceId = $request->query('resourceId');

        // Check if we're loading from old database
        $oldDatasetId = $request->query('oldDatasetId');

        // Validate oldDatasetId if provided
        if ($oldDatasetId !== null && (! is_numeric($oldDatasetId) || (int) $oldDatasetId <= 0)) {
            abort(400, 'Invalid dataset ID');
        }

        // Check if we're loading from XML upload session
        $xmlSessionKey = $request->query('xmlSession');

        // If xmlSession is provided, validate prefix and load from session
        if ($xmlSessionKey !== null && is_string($xmlSessionKey)) {
            // Security: Validate session key starts with expected prefix
            if (! str_starts_with($xmlSessionKey, 'xml_upload_')) {
                abort(400, 'Invalid session key format');
            }

            $sessionData = session()->pull($xmlSessionKey);

            if (! is_array($sessionData)) {
                // Session expired or invalid
                return redirect()->route('dashboard')
                    ->with('error', 'XML upload session expired. Please upload the file again.');
            }

            // Validate session data structure to prevent tampering
            $requiredArrayKeys = ['titles', 'licenses', 'authors', 'contributors', 'descriptions', 'dates', 'gcmdKeywords', 'freeKeywords', 'mslKeywords', 'coverages', 'fundingReferences', 'mslLaboratories'];
            foreach ($requiredArrayKeys as $key) {
                if (isset($sessionData[$key]) && ! is_array($sessionData[$key])) {
                    abort(400, 'Invalid session data structure: '.$key.' must be an array');
                }
            }

            // Validate scalar fields are strings if present
            $scalarKeys = ['doi', 'year', 'version', 'language', 'resourceType'];
            foreach ($scalarKeys as $key) {
                if (isset($sessionData[$key]) && ! is_string($sessionData[$key]) && ! is_numeric($sessionData[$key])) {
                    abort(400, 'Invalid session data structure: '.$key.' must be a string or numeric');
                }
            }

            return Inertia::render('editor', array_merge([
                'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
                'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
                'googleMapsApiKey' => config('services.google_maps.api_key'),
                'doi' => $sessionData['doi'] ?? '',
                'year' => $sessionData['year'] ?? '',
                'version' => $sessionData['version'] ?? '',
                'language' => $sessionData['language'] ?? '',
                'resourceType' => $sessionData['resourceType'] ?? '',
                'titles' => $sessionData['titles'] ?? [],
                'initialLicenses' => $sessionData['licenses'] ?? [],
                'authors' => $sessionData['authors'] ?? [],
                'contributors' => $sessionData['contributors'] ?? [],
                'descriptions' => $sessionData['descriptions'] ?? [],
                'dates' => $sessionData['dates'] ?? [],
                'gcmdKeywords' => $sessionData['gcmdKeywords'] ?? [],
                'freeKeywords' => $sessionData['freeKeywords'] ?? [],
                'mslKeywords' => $sessionData['mslKeywords'] ?? [],
                'coverages' => $sessionData['coverages'] ?? [],
                'relatedWorks' => [], // XML upload doesn't support related works yet
                'fundingReferences' => $sessionData['fundingReferences'] ?? [],
                'mslLaboratories' => $sessionData['mslLaboratories'] ?? [],
            ]));
        }

        // If oldDatasetId is provided, load from old SUMARIOPMD database
        if ($oldDatasetId !== null) {
            try {
                $loader = new OldDatasetEditorLoader;
                $editorData = $loader->loadForEditor((int) $oldDatasetId);

                return Inertia::render('editor', array_merge([
                    'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
                    'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
                    'googleMapsApiKey' => config('services.google_maps.api_key'),
                ], $editorData));
            } catch (\Exception $e) {
                // Log error and redirect back with error message
                Log::error('Failed to load old dataset in editor', [
                    'old_dataset_id' => $oldDatasetId,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('old-datasets')
                    ->with('error', 'Failed to load dataset from legacy database: '.$e->getMessage());
            }
        }

        // If resourceId is provided, load the resource from database
        if ($resourceId !== null) {
            /** @var \App\Models\Resource $resource */
            $resource = Resource::query()
                ->with([
                    'resourceType',
                    'language',
                    'titles.titleType',
                    'rights',
                    'creators.creatorable',
                    'creators.affiliations',
                    'descriptions',
                    'dates',
                    'subjects',
                    'geoLocations',
                    'relatedIdentifiers.identifierType',
                    'relatedIdentifiers.relationType',
                    'fundingReferences',
                ])
                ->findOrFail($resourceId);

            // Transform resource data for editor
            $titles = $resource->titles->map(function ($title) {
                $titleTypeSlug = $title->titleType?->slug;

                return [
                    'title' => $title->value,
                    // Frontend uses kebab-case slugs; main title is represented as 'main-title'
                    'titleType' => $title->isMainTitle()
                        ? 'main-title'
                        : Str::kebab($titleTypeSlug ?: ''),
                ];
            })->toArray();

            $licenses = $resource->rights->pluck('identifier')->toArray();

            // Group ResourceCreator entries by their creatorable (Person/Institution)
            // to handle cases where same person has multiple ResourceCreator records
            $creatorableGroups = $resource->creators
                ->filter(function ($creator) {
                    // Filter out MSL laboratories
                    if ($creator->creatorable_type === \App\Models\Institution::class) {
                        /** @var \App\Models\Institution $institution */
                        $institution = $creator->creatorable;

                        return $institution->name_identifier_scheme !== 'labid';
                    }

                    return true;
                })
                ->groupBy(function ($creator) {
                    return $creator->creatorable_type.'_'.$creator->creatorable_id;
                });

            // Transform creators - all ResourceCreator entries are creators in DataCite 4.6
            $authors = [];
            $contributors = [];

            foreach ($creatorableGroups as $group) {
                // In DataCite 4.6, all ResourceCreator entries are creators (no role distinction)
                /** @var \App\Models\ResourceCreator $firstEntry */
                $firstEntry = $group->first();
                $creatorable = $firstEntry->creatorable;

                // Collect all unique affiliations from all entries of this creator
                $allAffiliations = $group->flatMap(function ($creator) {
                    return $creator->affiliations;
                })->unique(function ($affiliation) {
                    // Unique by name and identifier combination
                    return $affiliation->name.'|'.($affiliation->identifier ?? 'null');
                });

                // All ResourceCreator entries are creators in DataCite 4.6
                $data = [
                    'position' => $firstEntry->position,
                    'isContact' => false, // Contact tracking will be handled differently
                ];

                if ($firstEntry->creatorable_type === \App\Models\Person::class) {
                    /** @var \App\Models\Person $creatorable */
                    $data['type'] = 'person';
                    // Map to frontend field names
                    $data['firstName'] = $creatorable->given_name ?? '';
                    $data['lastName'] = $creatorable->family_name ?? '';
                    $data['orcid'] = $creatorable->name_identifier ?? '';
                } elseif ($firstEntry->creatorable_type === \App\Models\Institution::class) {
                    /** @var \App\Models\Institution $creatorable */
                    $data['type'] = 'institution';
                    $data['institutionName'] = $creatorable->name ?? '';
                    $data['rorId'] = $creatorable->name_identifier ?? '';
                }

                // Add unique affiliations - map to frontend field names
                $data['affiliations'] = $allAffiliations->map(fn (\App\Models\Affiliation $affiliation): array => [
                    'value' => $affiliation->name,
                    'rorId' => $affiliation->identifier,
                ])->values()->toArray();

                $authors[] = $data;
            }

            // Sort by position
            usort($authors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            usort($contributors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            // Transform descriptions - map description_type to frontend format
            $descriptionTypeMap = [
                'abstract' => 'Abstract',
                'methods' => 'Methods',
                'series-information' => 'SeriesInformation',
                'table-of-contents' => 'TableOfContents',
                'technical-info' => 'TechnicalInfo',
                'other' => 'Other',
            ];

            $descriptions = $resource->descriptions->map(function ($description) use ($descriptionTypeMap) {
                // Map description_type slug to frontend format
                // @phpstan-ignore nullCoalesce.expr (defensive coding)
                $typeSlug = $description->descriptionType?->slug ?? 'other';
                $frontendType = $descriptionTypeMap[$typeSlug] ?? 'Other';

                return [
                    'type' => $frontendType,
                    'description' => $description->value,
                ];
            })->toArray();

            // Transform dates (exclude 'coverage' dates as they belong to spatial-temporal coverage)
            // Also exclude 'created' and 'updated' as these are auto-managed by the backend
            $dates = $resource->dates
                ->filter(function (ResourceDate $date): bool {
                    // Use null-safe operator to handle missing dateType relationship
                    // @phpstan-ignore nullCoalesce.expr (defensive coding for data integrity)
                    $slug = $date->dateType?->slug ?? '';

                    return ! in_array($slug, ['coverage', 'created', 'updated'], true);
                })
                ->map(function (ResourceDate $date): array {
                    // Convert datetime to date-only format (YYYY-MM-DD) for HTML date inputs
                    $startDate = '';
                    if ($date->start_date) {
                        try {
                            $startDate = \Carbon\Carbon::parse($date->start_date)->format('Y-m-d');
                        } catch (\Exception $e) {
                            $startDate = '';
                        }
                    }

                    $endDate = '';
                    if ($date->end_date) {
                        try {
                            $endDate = \Carbon\Carbon::parse($date->end_date)->format('Y-m-d');
                        } catch (\Exception $e) {
                            $endDate = '';
                        }
                    }

                    return [
                        // Use null-safe operator to handle missing dateType relationship
                        // @phpstan-ignore nullCoalesce.expr (defensive coding for data integrity)
                        'dateType' => $date->dateType?->slug ?? '',
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                    ];
                })
                ->values()
                ->toArray();

            // Transform subjects - separate free keywords and GCMD controlled keywords
            $freeKeywords = $resource->subjects
                ->filter(fn ($subject) => empty($subject->subject_scheme))
                ->pluck('value')
                ->toArray();

            // Transform controlled keywords (GCMD)
            $gcmdKeywords = $resource->subjects
                ->filter(fn ($subject) => ! empty($subject->subject_scheme))
                ->map(function ($subject) {
                    return [
                        'id' => $subject->classification_code ?? '',
                        'text' => $subject->value,
                        'path' => $subject->value, // Path may need to be extracted from subject text
                        'scheme' => $subject->subject_scheme ?? '',
                        'schemeURI' => $subject->scheme_uri ?? '',
                        'language' => 'en',
                    ];
                })->toArray();

            // Transform geoLocations to coverages format for frontend
            $coverages = $resource->geoLocations->map(function ($geoLocation) {
                // GeoLocation stores bounding box, but frontend expects different format
                return [
                    'id' => (string) $geoLocation->id,
                    'latMin' => $geoLocation->south_bound_latitude !== null ? (string) $geoLocation->south_bound_latitude : '',
                    'latMax' => $geoLocation->north_bound_latitude !== null ? (string) $geoLocation->north_bound_latitude : '',
                    'lonMin' => $geoLocation->west_bound_longitude !== null ? (string) $geoLocation->west_bound_longitude : '',
                    'lonMax' => $geoLocation->east_bound_longitude !== null ? (string) $geoLocation->east_bound_longitude : '',
                    'startDate' => '',
                    'endDate' => '',
                    'startTime' => '',
                    'endTime' => '',
                    'timezone' => 'UTC',
                    'description' => $geoLocation->place ?? '',
                ];
            })->toArray();

            // Transform related identifiers
            $relatedWorks = $resource->relatedIdentifiers
                ->sortBy('position')
                ->map(fn (\App\Models\RelatedIdentifier $relatedId): array => [
                    'identifier' => $relatedId->identifier,
                    'identifier_type' => $relatedId->identifierType->name,
                    'relation_type' => $relatedId->relationType->name,
                ])
                ->values()
                ->toArray();

            // Transform funding references
            $fundingReferences = $resource->fundingReferences
                ->sortBy('position')
                ->map(function ($funding) {
                    return [
                        'funderName' => $funding->funder_name,
                        'funderIdentifier' => $funding->funder_identifier ?? '',
                        'funderIdentifierType' => $funding->funder_identifier_type ?? '',
                        'awardNumber' => $funding->award_number ?? '',
                        'awardUri' => $funding->award_uri ?? '',
                        'awardTitle' => $funding->award_title ?? '',
                    ];
                })
                ->values()
                ->toArray();

            // Transform MSL Laboratories - these are stored as creators with labid identifier scheme
            $mslLaboratories = $resource->creators
                ->filter(function ($creator) {
                    if ($creator->creatorable_type === \App\Models\Institution::class) {
                        /** @var \App\Models\Institution $institution */
                        $institution = $creator->creatorable;

                        return $institution->name_identifier_scheme === 'labid';
                    }

                    return false;
                })
                ->map(function ($creator) {
                    /** @var \App\Models\Institution $institution */
                    $institution = $creator->creatorable;
                    $affiliation = $creator->affiliations->first();

                    return [
                        'identifier' => $institution->name_identifier ?? '',
                        'name' => $institution->name ?? '',
                        'affiliation_name' => $affiliation->name ?? '',
                        'affiliation_ror' => $affiliation->identifier ?? '',
                        'position' => $creator->position,
                    ];
                })
                ->values()
                ->toArray();

            return Inertia::render('editor', [
                'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
                'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
                'googleMapsApiKey' => config('services.google_maps.api_key'),
                'doi' => $resource->doi ?? '',
                'year' => (string) $resource->publication_year,
                'version' => $resource->version ?? '',
                'language' => $resource->language->code ?? '',
                'resourceType' => (string) $resource->resource_type_id,
                'resourceId' => (string) $resource->id,
                'titles' => $titles,
                'initialLicenses' => $licenses,
                'authors' => $authors,
                'contributors' => $contributors,
                'descriptions' => $descriptions,
                'dates' => $dates,
                'gcmdKeywords' => $gcmdKeywords,
                'freeKeywords' => $freeKeywords,
                'coverages' => $coverages,
                'relatedWorks' => $relatedWorks,
                'fundingReferences' => $fundingReferences,
                'mslLaboratories' => $mslLaboratories,
            ]);
        }

        // If no resourceId, check for individual query parameters (legacy/import mode)
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

    // User Management routes (Admin & Group Leader only)
    Route::middleware(['can.manage.users'])->prefix('users')->group(function () {
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
