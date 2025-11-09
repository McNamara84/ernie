<?php

use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LandingPagePreviewController;
use App\Http\Controllers\LandingPagePublicController;
use App\Http\Controllers\OldDatasetController;
use App\Http\Controllers\OldDataStatisticsController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\UploadXmlController;
use App\Http\Controllers\VocabularyController;
use App\Models\Resource;
use App\Models\Setting;
use App\Services\OldDatasetEditorLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
Route::get('datasets/{resourceId}', [LandingPagePublicController::class, 'show'])
    ->name('landing-page.show')
    ->where('resourceId', '[0-9]+');

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
                    'licenses',
                    'authors.authorable',
                    'authors.roles',
                    'authors.affiliations',
                    'descriptions',
                    'dates',
                    'keywords',
                    'controlledKeywords',
                    'coverages',
                    'relatedIdentifiers',
                    'fundingReferences',
                ])
                ->findOrFail($resourceId);

            // Transform resource data for editor
            $titles = $resource->titles->map(function ($title) {
                return [
                    'title' => $title->title,
                    'titleType' => $title->titleType->slug ?? '',
                ];
            })->toArray();

            $licenses = $resource->licenses->pluck('identifier')->toArray();

            // Group ResourceAuthor entries by their authorable (Person/Institution)
            // to handle cases where same person has multiple ResourceAuthor records
            // (e.g., one with 'author' role and one with 'contact-person' role)
            $authorableGroups = $resource->authors
                ->filter(function ($author) {
                    // Filter out MSL laboratories
                    if ($author->authorable_type === \App\Models\Institution::class) {
                        /** @var \App\Models\Institution $institution */
                        $institution = $author->authorable;

                        return $institution->identifier_type !== 'labid';
                    }

                    return true;
                })
                ->groupBy(function ($author) {
                    return $author->authorable_type.'_'.$author->authorable_id;
                });

            // Transform authors (those with 'author' role)
            $authors = [];
            $contributors = [];

            foreach ($authorableGroups as $group) {
                // Check if this group has the 'author' role
                $hasAuthorRole = $group->some(function ($author) {
                    return $author->roles->contains('slug', 'author');
                });

                // Check if this group has only non-author roles (contributor roles)
                $hasOnlyContributorRoles = $group->every(function ($author) use ($excludedAuthorRoles) {
                    $roles = $author->roles->pluck('slug')->toArray();

                    return empty(array_intersect($roles, $excludedAuthorRoles));
                });

                // Get the first entry to extract basic info
                /** @var \App\Models\ResourceAuthor $firstEntry */
                $firstEntry = $group->first();
                $authorable = $firstEntry->authorable;

                // Check if this is a contact person
                $isContact = $group->some(function ($author) {
                    return $author->roles->contains('slug', 'contact-person');
                });

                // Collect all unique affiliations from all entries of this authorable
                $allAffiliations = $group->flatMap(function ($author) {
                    return $author->affiliations;
                })->unique(function ($affiliation) {
                    // Unique by value and ror_id combination
                    return $affiliation->value.'|'.($affiliation->ror_id ?? 'null');
                });

                if ($hasAuthorRole) {
                    // This is an author
                    $data = [
                        'position' => $firstEntry->position,
                        'isContact' => $isContact,
                    ];

                    if ($firstEntry->authorable_type === \App\Models\Person::class) {
                        /** @var \App\Models\Person $authorable */
                        $data['type'] = 'person';
                        $data['firstName'] = $authorable->first_name ?? '';
                        $data['lastName'] = $authorable->last_name ?? '';
                        $data['orcid'] = $authorable->orcid ?? '';
                        $data['email'] = $firstEntry->email ?? '';
                        $data['website'] = $firstEntry->website ?? '';

                        // Mark ORCID as verified if it exists (to prevent re-verification)
                        if (! empty($authorable->orcid) && $authorable->orcid_verified_at) {
                            $data['orcidVerified'] = true;
                            $data['orcidVerifiedAt'] = $authorable->orcid_verified_at->toIso8601String();
                        }
                    } elseif ($firstEntry->authorable_type === \App\Models\Institution::class) {
                        $data['type'] = 'institution';
                        $data['institutionName'] = $authorable->name ?? '';
                        $data['rorId'] = $authorable->ror_id ?? '';
                    }

                    // Add unique affiliations
                    $data['affiliations'] = $allAffiliations->map(function ($affiliation) {
                        return [
                            'value' => $affiliation->value,
                            'rorId' => $affiliation->ror_id,
                        ];
                    })->values()->toArray();

                    $authors[] = $data;
                } elseif ($hasOnlyContributorRoles) {
                    // This is a contributor (no 'author' role)
                    $data = [
                        'position' => $firstEntry->position,
                    ];

                    if ($firstEntry->authorable_type === \App\Models\Person::class) {
                        /** @var \App\Models\Person $personAuthorable */
                        $personAuthorable = $authorable;
                        $data['type'] = 'person';
                        $data['firstName'] = $personAuthorable->first_name ?? '';
                        $data['lastName'] = $personAuthorable->last_name ?? '';
                        $data['orcid'] = $personAuthorable->orcid ?? '';

                        // Mark ORCID as verified if it exists (to prevent re-verification)
                        if (! empty($personAuthorable->orcid) && $personAuthorable->orcid_verified_at) {
                            $data['orcidVerified'] = true;
                            $data['orcidVerifiedAt'] = $personAuthorable->orcid_verified_at->toIso8601String();
                        }
                    } elseif ($firstEntry->authorable_type === \App\Models\Institution::class) {
                        /** @var \App\Models\Institution $institutionAuthorable */
                        $institutionAuthorable = $authorable;
                        $data['type'] = 'institution';
                        $data['institutionName'] = $institutionAuthorable->name ?? '';
                        $data['identifier'] = $institutionAuthorable->identifier ?? '';
                        $data['identifierType'] = $institutionAuthorable->identifier_type ?? '';
                    }

                    // Collect all unique roles from all entries, excluding author/contact-person
                    $allRoles = $group->flatMap(function ($author) {
                        return $author->roles;
                    })->unique('id')
                        ->filter(fn ($role) => ! in_array($role->slug, ['author', 'contact-person']))
                        ->pluck('name')
                        ->values()
                        ->toArray();

                    $data['roles'] = $allRoles;

                    // Add unique affiliations
                    $data['affiliations'] = $allAffiliations->map(function ($affiliation) {
                        return [
                            'value' => $affiliation->value,
                            'rorId' => $affiliation->ror_id,
                        ];
                    })->values()->toArray();

                    $contributors[] = $data;
                }
            }

            // Sort by position
            usort($authors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            usort($contributors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            // Transform descriptions
            // Convert database description_type (lowercase/kebab-case) to frontend format (PascalCase)
            $descriptionTypeMap = [
                'abstract' => 'Abstract',
                'methods' => 'Methods',
                'series-information' => 'SeriesInformation',
                'table-of-contents' => 'TableOfContents',
                'technical-info' => 'TechnicalInfo',
                'other' => 'Other',
            ];

            $descriptions = $resource->descriptions->map(function ($description) use ($descriptionTypeMap) {
                $frontendType = $descriptionTypeMap[$description->description_type] ?? 'Other';

                return [
                    'type' => $frontendType,
                    'description' => $description->description,
                ];
            })->toArray();

            // Transform dates (exclude 'coverage' dates as they belong to spatial-temporal coverage)
            $dates = $resource->dates
                ->filter(function ($date) {
                    return $date->date_type !== 'coverage';
                })
                ->map(function ($date) {
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
                        'dateType' => $date->date_type,
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                    ];
                })
                ->values()
                ->toArray();

            // Transform free keywords
            $freeKeywords = $resource->keywords->pluck('keyword')->toArray();

            // Transform controlled keywords (GCMD)
            $gcmdKeywords = $resource->controlledKeywords->map(function ($keyword) {
                return [
                    'id' => $keyword->keyword_id,
                    'text' => $keyword->text,
                    'path' => $keyword->path,
                    'scheme' => $keyword->scheme,
                    'schemeURI' => $keyword->scheme_uri ?? '',
                    'language' => $keyword->language ?? 'en',
                ];
            })->toArray();

            // Transform coverages
            // Transform coverages with proper date formatting
            $coverages = $resource->coverages->map(function ($coverage) {
                // Format dates for HTML date inputs (YYYY-MM-DD)
                $startDate = '';
                if ($coverage->start_date) {
                    try {
                        $startDate = \Carbon\Carbon::parse($coverage->start_date)->format('Y-m-d');
                    } catch (\Exception $e) {
                        $startDate = '';
                    }
                }

                $endDate = '';
                if ($coverage->end_date) {
                    try {
                        $endDate = \Carbon\Carbon::parse($coverage->end_date)->format('Y-m-d');
                    } catch (\Exception $e) {
                        $endDate = '';
                    }
                }

                return [
                    'id' => (string) $coverage->id,
                    'latMin' => $coverage->lat_min !== null ? (string) $coverage->lat_min : '',
                    'latMax' => $coverage->lat_max !== null ? (string) $coverage->lat_max : '',
                    'lonMin' => $coverage->lon_min !== null ? (string) $coverage->lon_min : '',
                    'lonMax' => $coverage->lon_max !== null ? (string) $coverage->lon_max : '',
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'startTime' => $coverage->start_time ?? '',
                    'endTime' => $coverage->end_time ?? '',
                    'timezone' => $coverage->timezone ?? 'UTC',
                    'description' => $coverage->description ?? '',
                ];
            })->toArray();

            // Transform related identifiers
            $relatedWorks = $resource->relatedIdentifiers
                ->sortBy('position')
                ->map(function ($relatedId) {
                    return [
                        'identifier' => $relatedId->identifier,
                        'identifier_type' => $relatedId->identifier_type,
                        'relation_type' => $relatedId->relation_type,
                    ];
                })
                ->values()
                ->toArray();            // Transform funding references
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

            // Transform MSL Laboratories
            $mslLaboratories = $resource->authors
                ->filter(function ($author) {
                    if ($author->authorable_type === \App\Models\Institution::class) {
                        /** @var \App\Models\Institution $institution */
                        $institution = $author->authorable;

                        return $institution->identifier_type === 'labid';
                    }

                    return false;
                })
                ->map(function ($author) {
                    /** @var \App\Models\Institution $institution */
                    $institution = $author->authorable;
                    $affiliation = $author->affiliations->first();

                    return [
                        'identifier' => $institution->identifier ?? '',
                        'name' => $institution->name ?? '',
                        'affiliation_name' => $affiliation->value ?? '',
                        'affiliation_ror' => $affiliation->ror_id ?? '',
                        'position' => $author->position,
                    ];
                })
                ->values()
                ->toArray();

            return Inertia::render('editor', [
                'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
                'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
                'googleMapsApiKey' => config('services.google_maps.api_key'),
                'doi' => $resource->doi ?? '',
                'year' => (string) $resource->year,
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
