<?php

namespace App\Queries;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ResourceListQuery
{
    private const DEFAULT_SORT_KEY = 'updated_at';

    private const DEFAULT_SORT_DIRECTION = 'desc';

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

    /**
     * @return array{0: Builder<Resource>, 1: string, 2: string, 3: array<string, mixed>}
     */
    public function build(Request $request): array
    {
        [$sortKey, $sortDirection] = $this->resolveSortState($request);
        $filters = $this->extractFilters($request);

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        $this->applySorting($query, $sortKey, $sortDirection);

        return [$query, $sortKey, $sortDirection, $filters];
    }

    /**
     * Resolve the requested sort state, falling back to the default when invalid.
     *
     * @return array{string, string}
     */
    public function resolveSortState(Request $request): array
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
     * @return Builder<Resource>
     */
    public function baseQuery(): Builder
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
                            'creatorable',
                            'affiliations',
                        ])
                        ->orderBy('position');
                },
                'contributors' => function ($query): void {
                    $query
                        ->with([
                            'contributorType',
                            'contributorable',
                            'affiliations',
                        ])
                        ->orderBy('position');
                },
            ]);
    }

    /**
     * Extract filters from the request.
     *
     * @return array<string, mixed>
     */
    public function extractFilters(Request $request): array
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
     * @param  Builder<Resource>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
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
        // Must match logic in ResourceListResourceSerializer::serialize():
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
     * @param  Builder<Resource>  $query
     */
    public function applySorting(Builder $query, string $sortKey, string $sortDirection): void
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
}
