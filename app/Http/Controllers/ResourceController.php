<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDoiRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\Right;
use App\Models\Title;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteXmlExporter;
use App\Services\DataCiteXmlValidator;
use App\Services\ResourceCacheService;
use App\Services\ResourceStorageService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ResourceController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MIN_PER_PAGE = 1;

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_SORT_KEY = 'updated_at';

    private const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly ResourceCacheService $cacheService,
        private readonly ResourceStorageService $storageService
    ) {}

    private const ALLOWED_SORT_KEYS = [
        'id',
        'doi',
        'title',
        'resourcetypegeneral',
        'first_author',
        'year',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        [$sortKey, $sortDirection] = $this->resolveSortState($request);
        $filters = $this->extractFilters($request);

        $query = $this->baseQuery();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $this->applySorting($query, $sortKey, $sortDirection);

        // Prepare cache key data
        $cacheFilters = array_merge($filters, [
            'sort' => $sortKey,
            'direction' => $sortDirection,
        ]);

        // Use caching for resource listing
        $resources = $this->cacheService->cacheResourceList(
            $query,
            $perPage,
            $page,
            $cacheFilters
        );

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = collect($items)
            ->map(fn (Resource $resource): array => $this->serializeResource($resource))
            ->all();

        return Inertia::render('resources', [
            'resources' => $resourcesData,
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
            'canImportFromDataCite' => $request->user()?->can('importFromDataCite', Resource::class) ?? false,
        ]);
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        try {
            [$resource, $isUpdate] = $this->storageService->store(
                $request->validated(),
                $request->user()?->id
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Unable to save resource. Please review the highlighted issues.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            // Log detailed context to help diagnose production issues
            Log::error('ResourceController::store failed', [
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'user_id' => $request->user()?->id,
                'resource_id' => $request->input('resourceId'),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            report($exception);

            return response()->json([
                'message' => 'Unable to save resource. Please try again later.',
            ], 500);
        }

        $message = $isUpdate ? 'Successfully updated resource.' : 'Successfully saved resource.';
        $status = $isUpdate ? 200 : 201;

        return response()->json([
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
            ],
        ], $status);
    }

    /**
     * Delete a resource.
     *
     * @param  Request  $request  The HTTP request - needed for user() access to check authorization.
     *                            While Laravel's route model binding could inject User directly,
     *                            using Request allows for consistent null-safety checks and follows
     *                            the pattern used in other controller methods.
     * @param  Resource  $resource  The resource to delete (injected via route model binding).
     */
    public function destroy(Request $request, Resource $resource): RedirectResponse
    {
        // Authorize deletion using ResourcePolicy - only Admin/GroupLeader can delete
        if ($request->user()?->cannot('delete', $resource)) {
            abort(403, 'You are not authorized to delete this resource.');
        }

        $resource->delete();

        return redirect()
            ->route('resources')
            ->with('success', 'Resource deleted successfully.');
    }

    /**
     * API endpoint for loading more resources (for infinite scrolling).
     */
    public function loadMore(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        [$sortKey, $sortDirection] = $this->resolveSortState($request);
        $filters = $this->extractFilters($request);

        $query = $this->baseQuery();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $this->applySorting($query, $sortKey, $sortDirection);

        // Prepare cache key data
        $cacheFilters = array_merge($filters, [
            'sort' => $sortKey,
            'direction' => $sortDirection,
        ]);

        // Use caching for resource listing
        $resources = $this->cacheService->cacheResourceList(
            $query,
            $perPage,
            $page,
            $cacheFilters
        );

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = collect($items)
            ->map(fn (Resource $resource): array => $this->serializeResource($resource))
            ->all();

        return response()->json([
            'resources' => $resourcesData,
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
        ]);
    }

    /**
     * API endpoint to get available filter options.
     */
    public function getFilterOptions(): JsonResponse
    {
        $resourceTypes = [];
        $curators = [];
        $yearMin = null;
        $yearMax = null;

        // Get distinct resource types
        try {
            $resourceTypes = \App\Models\ResourceType::query()
                ->whereHas('resources')
                ->orderBy('name')
                ->get(['name', 'slug'])
                ->map(fn ($type) => ['name' => $type->name, 'slug' => $type->slug])
                ->all();
        } catch (Throwable $e) {
            Log::warning('Failed to load resource type filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Get distinct curators (users who updated or created resources)
        // Prioritize updatedBy, fallback to createdBy if never updated
        try {
            $resourceQuery = Resource::query();
            $hasUpdatedBy = Schema::hasColumn('resources', 'updated_by_user_id');
            $hasCreatedBy = Schema::hasColumn('resources', 'created_by_user_id');

            $updatedByIds = collect();
            $createdByIdsWithoutUpdates = collect();

            if ($hasUpdatedBy) {
                $updatedByIds = (clone $resourceQuery)
                    ->whereNotNull('updated_by_user_id')
                    ->distinct()
                    ->pluck('updated_by_user_id');
            }

            if ($hasCreatedBy) {
                $createdByQuery = clone $resourceQuery;

                if ($hasUpdatedBy) {
                    $createdByQuery->whereNull('updated_by_user_id');
                }

                $createdByIdsWithoutUpdates = $createdByQuery
                    ->whereNotNull('created_by_user_id')
                    ->distinct()
                    ->pluck('created_by_user_id');
            }

            $curatorIds = $updatedByIds->merge($createdByIdsWithoutUpdates)->unique()->values();

            if ($curatorIds->isNotEmpty()) {
                $curators = User::query()
                    ->whereIn('id', $curatorIds->all())
                    ->orderBy('name')
                    ->pluck('name')
                    ->unique()
                    ->values()
                    ->all();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to load curator filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Get year range
        try {
            if (Schema::hasColumn('resources', 'year')) {
                $yearMin = Resource::query()->min('year');
                $yearMax = Resource::query()->max('year');
            }

            // When there are no resources yet, min/max can be null.
            // Keep the API shape stable (numbers) to avoid frontend crashes.
            if ($yearMin === null || $yearMax === null) {
                $currentYear = (int) now()->year;
                $yearMin = $currentYear;
                $yearMax = $currentYear;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to load year range filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Available publication statuses
        $statuses = ['curation', 'review', 'published'];

        return response()->json([
            'resource_types' => $resourceTypes,
            'curators' => $curators,
            'year_range' => [
                'min' => $yearMin,
                'max' => $yearMax,
            ],
            'statuses' => $statuses,
        ]);
    }

    /**
     * Resolve the requested sort state, falling back to the default when invalid.
     *
     * @return array{string, string}
     */
    protected function resolveSortState(Request $request): array
    {
        $requestedKey = strtolower((string) $request->get('sort_key', self::DEFAULT_SORT_KEY));
        $requestedDirection = strtolower((string) $request->get('sort_direction', self::DEFAULT_SORT_DIRECTION));

        $sortKey = in_array($requestedKey, self::ALLOWED_SORT_KEYS, true)
            ? $requestedKey
            : self::DEFAULT_SORT_KEY;

        $sortDirection = in_array($requestedDirection, self::ALLOWED_SORT_DIRECTIONS, true)
            ? $requestedDirection
            : self::DEFAULT_SORT_DIRECTION;

        return [$sortKey, $sortDirection];
    }

    /**
     * Base query for resource listing with optimized eager loading.
     *
     * This query eager loads all necessary relationships to avoid N+1 problems:
     * - Polymorphic relations (creators, contributors) with their affiliations
     * - All lookup tables (resource types, languages, title types)
     * - User relationships for audit tracking
     *
     * Performance: ~10 queries for 50+ resources with complex relationships
     *
     * @return \Illuminate\Database\Eloquent\Builder<Resource>
     */
    protected function baseQuery()
    {
        return Resource::query()
            ->with([
                'resourceType:id,name,slug',
                'language:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'landingPage:id,resource_id,is_published,published_at,preview_token',
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'value', 'title_type_id'])
                        ->with(['titleType:id,name,slug'])
                        ->orderBy('id');
                },
                'rights:id,identifier,name',
                // Eager load dates with the dateType relation.
                // Note: date_type_id MUST be included in the select() for the dateType relation
                // to work, as Eloquent uses it as the foreign key for the belongsTo relation.
                'dates' => function ($query): void {
                    $query->select(['id', 'resource_id', 'date_type_id', 'date_value', 'start_date', 'end_date'])
                        ->with(['dateType:id,slug']);
                },
                'creators' => function ($query): void {
                    $query
                        ->with([
                            'creatorable', // Eager load Person or Institution
                            'affiliations', // Eager load affiliations
                        ])
                        ->orderBy('position');
                },
                'contributors' => function ($query): void {
                    $query
                        ->with([
                            'contributorType',
                            'contributorable', // Eager load Person or Institution
                            'affiliations', // Eager load affiliations
                        ])
                        ->orderBy('position');
                },
            ]);
    }

    /**
    /**
     * Extract filters from the request.
     *
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        $filters = [];

        // Resource Type filter
        if ($request->has('resource_type')) {
            $resourceType = $request->input('resource_type');
            if (is_array($resourceType)) {
                $filters['resource_type'] = array_filter($resourceType);
            } elseif (! empty($resourceType)) {
                $filters['resource_type'] = [$resourceType];
            }
        }

        // Curator filter
        if ($request->has('curator')) {
            $curator = $request->input('curator');
            if (is_array($curator)) {
                $filters['curator'] = array_filter($curator);
            } elseif (! empty($curator)) {
                $filters['curator'] = [$curator];
            }
        }

        // Status filter (currently only 'curation' for new resources)
        if ($request->has('status')) {
            $status = $request->input('status');
            if (is_array($status)) {
                $filters['status'] = array_filter($status);
            } elseif (! empty($status)) {
                $filters['status'] = [$status];
            }
        }

        // Publication Year Range
        if ($request->has('year_from') && is_numeric($request->input('year_from'))) {
            $filters['year_from'] = (int) $request->input('year_from');
        }

        if ($request->has('year_to') && is_numeric($request->input('year_to'))) {
            $filters['year_to'] = (int) $request->input('year_to');
        }

        // Text Search
        if ($request->has('search')) {
            $search = trim((string) $request->input('search'));
            if (! empty($search)) {
                $filters['search'] = $search;
            }
        }

        // Date Range filters
        if ($request->has('created_from')) {
            $createdFrom = $request->input('created_from');
            if (! empty($createdFrom)) {
                $filters['created_from'] = $createdFrom;
            }
        }

        if ($request->has('created_to')) {
            $createdTo = $request->input('created_to');
            if (! empty($createdTo)) {
                $filters['created_to'] = $createdTo;
            }
        }

        if ($request->has('updated_from')) {
            $updatedFrom = $request->input('updated_from');
            if (! empty($updatedFrom)) {
                $filters['updated_from'] = $updatedFrom;
            }
        }

        if ($request->has('updated_to')) {
            $updatedTo = $request->input('updated_to');
            if (! empty($updatedTo)) {
                $filters['updated_to'] = $updatedTo;
            }
        }

        return $filters;
    }

    /**
     * Apply filters to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<resource>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        // Resource Type filter
        if (! empty($filters['resource_type'])) {
            $query->whereHas('resourceType', function ($q) use ($filters) {
                $q->whereIn('slug', $filters['resource_type']);
            });
        }

        // Curator filter - filter by updatedBy (last editor), fallback to createdBy if never updated
        if (! empty($filters['curator'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('updatedBy', function ($subQ) use ($filters) {
                    $subQ->whereIn('name', $filters['curator']);
                })->orWhere(function ($subQ) use ($filters) {
                    // Fallback: if never updated (updated_by_user_id is null), check creator
                    $subQ->whereNull('updated_by_user_id')
                        ->whereHas('createdBy', function ($creatorQ) use ($filters) {
                            $creatorQ->whereIn('name', $filters['curator']);
                        });
                });
            });
        }

        // Status filter - filter based on DOI and landing page status
        // Must match logic in serializeResource():
        // - curation: no DOI OR (has DOI but no landing page)
        // - review: has DOI AND has landing page with is_published = false
        // - published: has DOI AND has landing page with is_published = true
        if (! empty($filters['status'])) {
            $statuses = $filters['status'];
            $query->where(function ($q) use ($statuses) {
                foreach ($statuses as $status) {
                    if ($status === 'curation') {
                        // Curation: No DOI OR (has DOI but no landing page)
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNull('doi')
                                ->orWhereDoesntHave('landingPage');
                        });
                    } elseif ($status === 'review') {
                        // Review: DOI registered + landing page with is_published = false
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', false);
                                });
                        });
                    } elseif ($status === 'published') {
                        // Published: DOI registered + landing page with is_published = true
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', true);
                                });
                        });
                    }
                }
            });
        }

        // Year range filter
        if (isset($filters['year_from'])) {
            $query->where('year', '>=', $filters['year_from']);
        }

        if (isset($filters['year_to'])) {
            $query->where('year', '<=', $filters['year_to']);
        }

        // Text search (title, DOI)
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('doi', 'like', "%{$search}%")
                    ->orWhereHas('titles', function ($titleQuery) use ($search) {
                        $titleQuery->where('title', 'like', "%{$search}%");
                    });
            });
        }

        // Created date range
        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        // Updated date range
        if (! empty($filters['updated_from'])) {
            $query->whereDate('updated_at', '>=', $filters['updated_from']);
        }

        if (! empty($filters['updated_to'])) {
            $query->whereDate('updated_at', '<=', $filters['updated_to']);
        }
    }

    /**
     * Apply sorting to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<resource>  $query
     */
    protected function applySorting($query, string $sortKey, string $sortDirection): void
    {
        switch ($sortKey) {
            case 'title':
                // Sort by first title
                $query->leftJoin('titles', function ($join) {
                    $join->on('resources.id', '=', 'titles.resource_id')
                        ->whereRaw('titles.id = (SELECT MIN(id) FROM titles WHERE resource_id = resources.id)');
                })
                    ->orderBy('titles.value', $sortDirection)
                    ->select('resources.*');
                break;

            case 'resourcetypegeneral':
                $query->leftJoin('resource_types', 'resources.resource_type_id', '=', 'resource_types.id')
                    ->orderBy('resource_types.name', $sortDirection)
                    ->select('resources.*');
                break;

            case 'first_author':
                // Sort by first creator's family name
                $query->leftJoin('resource_creators', function ($join) {
                    $join->on('resources.id', '=', 'resource_creators.resource_id')
                        ->whereRaw('resource_creators.position = (SELECT MIN(position) FROM resource_creators WHERE resource_id = resources.id)');
                })
                    ->leftJoin('persons', function ($join) {
                        $join->on('resource_creators.creatorable_id', '=', 'persons.id')
                            ->where('resource_creators.creatorable_type', '=', Person::class);
                    })
                    ->leftJoin('institutions', function ($join) {
                        $join->on('resource_creators.creatorable_id', '=', 'institutions.id')
                            ->where('resource_creators.creatorable_type', '=', Institution::class);
                    })
                    ->orderByRaw("COALESCE(persons.family_name, institutions.name) {$sortDirection}")
                    ->select('resources.*');
                break;

            case 'curator':
                $query->leftJoin('users as creator_users', 'resources.created_by_user_id', '=', 'creator_users.id')
                    ->orderBy('creator_users.name', $sortDirection)
                    ->select('resources.*');
                break;

            case 'publicstatus':
                // All resources have 'curation' status, so this doesn't really sort
                // But we keep it for consistency
                $query->orderBy('id', $sortDirection);
                break;

            default:
                // Direct column sorting (id, doi, year, created_at, updated_at)
                $query->orderBy($sortKey, $sortDirection);
                break;
        }
    }

    /**
     * Serialize a Resource model to an array for API responses.
     *
     * @param  Resource  $resource  The resource to serialize (must have titles, rights, creators relationships loaded)
     * @return array<string, mixed> The serialized resource data
     */
    private function serializeResource(Resource $resource): array
    {
        // In development, assert all required relations are loaded to detect N+1 queries
        if (app()->environment('local', 'testing')) {
            $this->assertRelationsLoaded($resource);
        }

        // Get first creator
        $firstCreator = $resource->creators->first();
        $firstCreatorData = null;

        if ($firstCreator) {
            $creatorable = $firstCreator->creatorable;
            if ($creatorable instanceof Person) {
                $firstCreatorData = [
                    'givenName' => $creatorable->given_name,
                    'familyName' => $creatorable->family_name,
                ];
            } elseif ($creatorable instanceof Institution) {
                $firstCreatorData = [
                    'name' => $creatorable->name,
                ];
            }
        }

        // Determine publication status based on DOI and landing page status
        $publicStatus = 'curation'; // Default status
        if ($resource->doi && $resource->landingPage) {
            $publicStatus = $resource->landingPage->is_published ? 'published' : 'review';
        }

        // Get DataCite dates (Created/Updated) from the dates relation instead of Eloquent timestamps.
        // This preserves the original creation/update dates from imported datasets.
        //
        // Performance note: The dates relation is eager loaded by baseQuery() with only the
        // necessary columns (id, resource_id, date_type_id, date_value, start_date, end_date)
        // and the dateType relation. Filtering is done on the in-memory collection which is
        // efficient for the typical small number of dates per resource (usually <10).
        //
        // If resources commonly have many dates, consider indexing dates by type during
        // eager loading using a custom accessor or query scope.
        $createdDateRecord = $resource->dates->first(fn ($date) => $date->dateType->slug === 'Created');
        $createdDate = $createdDateRecord !== null
            ? ($createdDateRecord->date_value ?? $createdDateRecord->start_date)
            : null;

        // For Updated dates, get the most recent one by sorting only Updated dates
        $updatedDateRecord = $resource->dates
            ->filter(fn ($date) => $date->dateType->slug === 'Updated')
            ->sortByDesc(fn ($date) => $date->date_value ?? $date->start_date ?? '')
            ->first();
        $updatedDate = $updatedDateRecord !== null
            ? ($updatedDateRecord->date_value ?? $updatedDateRecord->start_date)
            : null;

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'year' => $resource->publication_year,
            'version' => $resource->version,
            // Use DataCite dates if available, otherwise fall back to Eloquent timestamps
            'created_at' => $createdDate ?? $resource->created_at?->toIso8601String(),
            'updated_at' => $updatedDate ?? $resource->updated_at?->toIso8601String(),
            'curator' => $resource->updatedBy?->name ?? $resource->createdBy?->name, // @phpstan-ignore nullsafe.neverNull (updatedBy can be null if updated_by_user_id is null)
            'publicstatus' => $publicStatus,
            'resourcetypegeneral' => $resource->resourceType?->name,
            'resource_type' => $resource->resourceType ? [
                'name' => $resource->resourceType->name,
                'slug' => $resource->resourceType->slug,
            ] : null,
            'language' => $resource->language ? [
                'code' => $resource->language->code,
                'name' => $resource->language->name,
            ] : null,
            'title' => $resource->titles->first()?->value,
            'titles' => $resource->titles
                ->map(static function (Title $title): array {
                    return [
                        'title' => $title->value,
                        // Use null-safe operator for legacy data where titleType may be null
                        'title_type' => $title->titleType !== null ? [
                            'name' => $title->titleType->name,
                            'slug' => $title->titleType->slug,
                        ] : [
                            'name' => 'Main Title',
                            'slug' => 'MainTitle',
                        ],
                    ];
                })
                ->values()
                ->all(),
            'rights' => $resource->rights
                ->map(static function (Right $right): array {
                    return [
                        'identifier' => $right->identifier,
                        'name' => $right->name,
                    ];
                })
                ->values()
                ->all(),
            'first_author' => $firstCreatorData,
            'landingPage' => $resource->landingPage ? [
                'id' => $resource->landingPage->id,
                'is_published' => $resource->landingPage->is_published,
                'public_url' => $resource->landingPage->public_url,
            ] : null,
        ];
    }

    /**
     * Export a resource as DataCite JSON
     */
    public function exportDataCiteJson(Resource $resource): SymfonyResponse
    {
        $exporter = new DataCiteJsonExporter;
        $dataCiteJson = $exporter->export($resource);

        // Generate filename with timestamp
        $timestamp = now()->format('YmdHis');
        $filename = "resource-{$resource->id}-{$timestamp}-datacite.json";

        return response()->json($dataCiteJson, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export a resource as DataCite XML
     */
    public function exportDataCiteXml(Resource $resource): SymfonyResponse
    {
        try {
            // Generate XML
            $exporter = new DataCiteXmlExporter;
            $xml = $exporter->export($resource);

            // Validate against XSD schema
            $validator = new DataCiteXmlValidator;
            $isValid = $validator->validate($xml);

            // Generate filename with timestamp
            $timestamp = now()->format('YmdHis');
            $filename = "resource-{$resource->id}-{$timestamp}-datacite.xml";

            $headers = [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            // Add validation warning header if validation failed
            if (! $isValid && $validator->hasWarnings()) {
                $warningMessage = $validator->getFormattedWarningMessage();
                if ($warningMessage) {
                    $headers['X-Validation-Warning'] = base64_encode($warningMessage);
                }
            }

            return response($xml, 200, $headers);

        } catch (\Exception $e) {
            // Log full exception details for debugging
            Log::error('DataCite XML export failed', [
                'resource_id' => $resource->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return generic error message in production, detailed in development
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred while generating the XML export. Please contact support if the problem persists.';

            return response()->json([
                'error' => 'Failed to export DataCite XML',
                'message' => $message,
            ], 500);
        }
    }

    /**
     * Register a DOI with DataCite or update metadata for existing DOI
     */
    public function registerDoi(RegisterDoiRequest $request, Resource $resource): JsonResponse
    {
        try {
            // Check if resource has a landing page
            $resource->load('landingPage');
            if (! $resource->landingPage) {
                return response()->json([
                    'error' => 'Landing page required',
                    'message' => 'A landing page must be created before registering a DOI. Please set up a landing page first.',
                ], 422);
            }

            // Resolve service from container (allows testing with fake service)
            $service = app(DataCiteRegistrationService::class);

            // Check if DOI already exists - if yes, update metadata instead of registering
            if ($resource->doi) {
                Log::info('Updating existing DOI metadata', [
                    'resource_id' => $resource->id,
                    'doi' => $resource->doi,
                ]);

                $response = $service->updateMetadata($resource);

                // Extract DOI from response
                $doi = $response['data']['id'] ?? $resource->doi;

                return response()->json([
                    'success' => true,
                    'message' => 'DOI metadata updated successfully',
                    'doi' => $doi,
                    'mode' => $service->isTestMode() ? 'test' : 'production',
                    'updated' => true,
                ]);
            }

            // Register new DOI
            $validated = $request->validated();
            $prefix = $validated['prefix'];

            Log::info('Registering new DOI', [
                'resource_id' => $resource->id,
                'prefix' => $prefix,
                'test_mode' => $service->isTestMode(),
            ]);

            $response = $service->registerDoi($resource, $prefix);

            // Extract DOI from DataCite response
            $doi = $response['data']['id'] ?? null;

            if (! $doi) {
                Log::error('DataCite response missing DOI', [
                    'resource_id' => $resource->id,
                    'response' => $response,
                ]);

                return response()->json([
                    'error' => 'Registration incomplete',
                    'message' => 'DOI was registered but the response did not contain the DOI identifier.',
                ], 500);
            }

            // Save DOI to resource
            $resource->doi = $doi;
            $resource->save();

            Log::info('DOI saved to resource', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'DOI registered successfully',
                'doi' => $doi,
                'mode' => $service->isTestMode() ? 'test' : 'production',
                'updated' => false,
            ]);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid DOI registration request', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid request',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\RuntimeException $e) {
            Log::warning('DOI registration runtime error', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage(),
            ], 422);

        } catch (RequestException $e) {
            // DataCite API error
            // PHPDoc indicates response is always present, but it can be null at runtime
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : 500;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $apiError = $response !== null ? $response->json() : null;

            Log::error('DataCite API error during DOI registration', [
                'resource_id' => $resource->id,
                'status' => $statusCode,
                'error' => $e->getMessage(),
                'api_response' => $apiError,
            ]);

            // Extract error message from DataCite response
            $errorMessage = 'Failed to communicate with DataCite API.';
            if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
                $firstError = $apiError['errors'][0];
                $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
            }

            return response()->json([
                'error' => 'DataCite API error',
                'message' => $errorMessage,
                'details' => config('app.debug') ? $apiError : null,
            ], $statusCode >= 400 && $statusCode < 500 ? $statusCode : 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during DOI registration', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Unexpected error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during DOI registration. Please contact support.',
            ], 500);
        }
    }

    /**
     * Get available DataCite prefixes based on test mode configuration
     */
    public function getDataCitePrefixes(): JsonResponse
    {
        $testMode = (bool) config('datacite.test_mode', true);

        $prefixes = [
            'test' => config('datacite.test.prefixes', []),
            'production' => config('datacite.production.prefixes', []),
            'test_mode' => $testMode,
        ];

        return response()->json($prefixes);
    }

    /**
     * Assert that all required relations are loaded to prevent N+1 queries.
     *
     * This method is only called in development environment and throws an
     * exception if any required relation is not eager loaded.
     *
     *
     * @throws \RuntimeException if a required relation is not loaded
     */
    private function assertRelationsLoaded(Resource $resource): void
    {
        $requiredRelations = [
            'creators',
            'contributors',
            'titles',
            'rights',
            'dates',
            'resourceType',
            'language',
            'createdBy',
            'updatedBy',
            'landingPage',
        ];

        foreach ($requiredRelations as $relation) {
            if (! $resource->relationLoaded($relation)) {
                throw new \RuntimeException(
                    "Relation '{$relation}' not loaded on Resource #{$resource->id}. N+1 query detected! ".
                    'Ensure baseQuery() eager loads all required relationships.'
                );
            }
        }

        // Check that dateType is loaded on dates to prevent N+1 when accessing $date->dateType->slug
        if ($resource->dates->isNotEmpty()) {
            $firstDate = $resource->dates->first();
            if (! $firstDate->relationLoaded('dateType')) {
                throw new \RuntimeException(
                    'Relation dateType not loaded on ResourceDate. N+1 query detected!'
                );
            }
        }

        // Check nested relations on creators if creators exist
        if ($resource->creators->isNotEmpty()) {
            $firstCreator = $resource->creators->first();
            if (! $firstCreator->relationLoaded('creatorable')) {
                throw new \RuntimeException(
                    'Relation creatorable not loaded on ResourceCreator. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstCreator->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceCreator. N+1 query detected!'
                );
            }
        }

        // Check nested relations on contributors if contributors exist
        if ($resource->contributors->isNotEmpty()) {
            $firstContributor = $resource->contributors->first();
            if (! $firstContributor->relationLoaded('contributorable')) {
                throw new \RuntimeException(
                    'Relation contributorable not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            if (! $firstContributor->relationLoaded('contributorType')) {
                throw new \RuntimeException(
                    'Relation contributorType not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstContributor->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceContributor. N+1 query detected!'
                );
            }
        }
    }
}
