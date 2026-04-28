<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Services\ResourceCacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the resource listing query:
 * eager-loading, filtering, sorting, completeness constraints and caching.
 *
 * Used by both `ResourceController@index` (Inertia render) and
 * `ResourceFilterController@loadMore` (JSON pagination) so the two endpoints
 * can never drift apart.
 */
final readonly class ResourceQueryBuilder
{
    public function __construct(
        private ResourceCacheService $cacheService,
    ) {}

    /**
     * Build, filter, sort and paginate the resource listing query.
     *
     * @param  array{
     *     page:int,
     *     perPage:int,
     *     sortKey:string,
     *     sortDirection:string,
     *     filters:array<string,mixed>
     * }  $criteria
     * @return LengthAwarePaginator<int, Resource>
     */
    public function paginate(array $criteria): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $criteria['filters']);
        $this->applySorting($query, $criteria['sortKey'], $criteria['sortDirection']);

        $cacheFilters = array_merge($criteria['filters'], [
            'sort' => $criteria['sortKey'],
            'direction' => $criteria['sortDirection'],
        ]);

        return $this->cacheService->cacheResourceList(
            $query,
            $criteria['perPage'],
            $criteria['page'],
            $cacheFilters
        );
    }

    /**
     * Base query for resource listing with optimized eager loading.
     *
     * Eager loads all relationships consumed by `ResourceListItemResource`
     * to avoid N+1 problems. Physical Object resources (IGSNs) are excluded
     * because they have their own dedicated page at /igsns.
     *
     * Performance: ~10 queries for 50+ resources with complex relationships.
     *
     * @return Builder<Resource>
     */
    public function baseQuery(): Builder
    {
        return Resource::query()
            ->whereDoesntHave('resourceType', function ($query): void {
                $query->where('slug', 'physical-object');
            })
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
                'descriptions' => function ($query): void {
                    $query->select(['id', 'resource_id', 'value', 'description_type_id'])
                        ->with(['descriptionType:id,slug']);
                },
                // Note: date_type_id MUST be in the select() for the dateType belongsTo relation.
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
                            'contributorTypes',
                            'contributorable',
                            'affiliations',
                        ])
                        ->orderBy('position');
                },
            ]);
    }

    /**
     * Apply filters to the query.
     *
     * @param  Builder<Resource>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
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
                    $subQ->whereNull('updated_by_user_id')
                        ->whereHas('createdBy', function ($creatorQ) use ($filters) {
                            $creatorQ->whereIn('name', $filters['curator']);
                        });
                });
            });
        }

        // Status filter - keep semantics in sync with Resource::publicStatus():
        // - draft: missing mandatory fields (type, year, creators, rights, main title or abstract)
        // - curation: complete + no DOI OR (has DOI but no landing page)
        // - review: complete + has DOI AND landing page with is_published = false
        // - published: complete + has DOI AND landing page with is_published = true
        if (! empty($filters['status'])) {
            $statuses = $filters['status'];
            $query->where(function ($q) use ($statuses) {
                foreach ($statuses as $status) {
                    if ($status === 'draft') {
                        $q->orWhere(function ($subQ) {
                            $subQ->where(function ($inner) {
                                $inner->whereNull('resource_type_id')
                                    ->orWhereNull('publication_year')
                                    ->orWhereDoesntHave('creators')
                                    ->orWhereDoesntHave('rights')
                                    ->orWhere(function ($titleQ) {
                                        // No Main Title with non-empty trimmed value
                                        // (legacy: NULL title_type_id counts as MainTitle)
                                        $titleQ->whereDoesntHave('titles', function ($tQ) {
                                            $tQ->whereRaw("TRIM(value) != ''")
                                                ->where(function ($typeQ) {
                                                    $typeQ->whereNull('title_type_id')
                                                        ->orWhereHas('titleType', function ($ttQ) {
                                                            $ttQ->where('slug', 'MainTitle');
                                                        });
                                                });
                                        });
                                    })
                                    ->orWhereDoesntHave('descriptions', function ($dQ) {
                                        $dQ->whereRaw("TRIM(value) != ''")
                                            ->whereHas('descriptionType', function ($dtQ) {
                                                $dtQ->where('slug', 'Abstract');
                                            });
                                    });
                            });
                        });
                    } elseif ($status === 'curation') {
                        $q->orWhere(function ($subQ) {
                            $this->applyCompletenessConstraints($subQ)
                                ->where(function ($inner) {
                                    $inner->whereNull('doi')
                                        ->orWhereDoesntHave('landingPage');
                                });
                        });
                    } elseif ($status === 'review') {
                        $q->orWhere(function ($subQ) {
                            $this->applyCompletenessConstraints($subQ)
                                ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', false);
                                });
                        });
                    } elseif ($status === 'published') {
                        $q->orWhere(function ($subQ) {
                            $this->applyCompletenessConstraints($subQ)
                                ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', true);
                                });
                        });
                    }
                }
            });
        }

        if (isset($filters['year_from'])) {
            $query->where('publication_year', '>=', $filters['year_from']);
        }

        if (isset($filters['year_to'])) {
            $query->where('publication_year', '<=', $filters['year_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('doi', 'like', "%{$search}%")
                    ->orWhereHas('titles', function ($titleQuery) use ($search) {
                        $titleQuery->where('value', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

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
                    ->orderByRaw(match ($sortDirection) {
                        'desc' => 'COALESCE(persons.family_name, institutions.name) desc',
                        default => 'COALESCE(persons.family_name, institutions.name) asc',
                    })
                    ->select('resources.*');
                break;

            case 'curator':
                // Match the UI's effective curator (ResourceListItemResource):
                //   curator = updatedBy?->name ?? createdBy?->name
                // so that the sort order is consistent with the displayed name.
                $query->leftJoin('users as updater_users', 'resources.updated_by_user_id', '=', 'updater_users.id')
                    ->leftJoin('users as creator_users', 'resources.created_by_user_id', '=', 'creator_users.id')
                    ->orderByRaw(match ($sortDirection) {
                        'desc' => 'COALESCE(updater_users.name, creator_users.name) desc',
                        default => 'COALESCE(updater_users.name, creator_users.name) asc',
                    })
                    ->select('resources.*');
                break;

            case 'publicstatus':
                // Status (draft/curation/review/published) is computed at serialization time,
                // not stored in the DB, so we fall back to sorting by id.
                $query->orderBy('id', $sortDirection);
                break;

            case 'year':
                $query->orderBy('publication_year', $sortDirection);
                break;

            default:
                $query->orderBy($sortKey, $sortDirection);
                break;
        }
    }

    /**
     * Apply resource completeness constraints to a query builder.
     *
     * Ensures the resource has all mandatory fields: resource_type_id, publication_year,
     * at least one creator, at least one license, a Main Title with non-empty value,
     * and an Abstract description with non-empty value.
     *
     * Legacy: NULL title_type_id is treated as MainTitle (consistent with Title::isMainTitle()).
     *
     * @param  Builder<Resource>  $query
     * @return Builder<Resource>
     */
    private function applyCompletenessConstraints(Builder $query): Builder
    {
        return $query->whereNotNull('resource_type_id')
            ->whereNotNull('publication_year')
            ->whereHas('creators')
            ->whereHas('rights')
            ->whereHas('titles', function ($tQ) {
                $tQ->whereRaw("TRIM(value) != ''")
                    ->where(function ($typeQ) {
                        $typeQ->whereNull('title_type_id')
                            ->orWhereHas('titleType', function ($ttQ) {
                                $ttQ->where('slug', 'MainTitle');
                            });
                    });
            })
            ->whereHas('descriptions', function ($dQ) {
                $dQ->whereRaw("TRIM(value) != ''")
                    ->whereHas('descriptionType', function ($dtQ) {
                        $dtQ->where('slug', 'Abstract');
                    });
            });
    }
}
