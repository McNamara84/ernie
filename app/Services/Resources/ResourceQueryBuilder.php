<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

/**
 * Single source of truth for the internal resource list.
 *
 * Filtering and sorting use the denormalized listing projection; the bounded
 * relationship graph is still eager-loaded to preserve the response contract.
 */
final readonly class ResourceQueryBuilder
{
    public function __construct(
        private ResourceCacheService $cacheService,
        private ListingCountService $countService,
        private ResourceListingProjectionRefreshService $projectionRefreshScheduler,
        private ResourceListingCursorCodecService $cursorCodec,
    ) {}

    /**
     * Load one stable result slice without OFFSET or an exact COUNT query.
     *
     * @param  array{cursor:?string,perPage:int,sortKey:string,sortDirection:string,filters:array<string,mixed>}  $criteria
     * @return CursorPaginator<int, Resource>
     */
    public function cursorPaginate(array $criteria): CursorPaginator
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $criteria['filters']);
        $this->applySorting($query, $criteria['sortKey'], $criteria['sortDirection']);

        $decodedCursor = $criteria['cursor'] === null
            ? null
            : $this->cursorCodec->decode($criteria['cursor'], $this->cursorContextFingerprint($criteria));

        return $this->cacheService->cacheCursorResourceList(
            $query,
            $criteria['perPage'],
            $decodedCursor,
            [
                ...$criteria['filters'],
                'sort' => $criteria['sortKey'],
                'direction' => $criteria['sortDirection'],
            ],
        );
    }

    /** @param array{filters:array<string,mixed>} $criteria */
    public function count(array $criteria): int
    {
        return $this->countService->remember(
            CacheKey::RESOURCE_LISTING_COUNT,
            $criteria['filters'],
            function () use ($criteria): int {
                $query = $this->baseQuery();
                $this->applyFilters($query, $criteria['filters']);

                return $query->count('resources.id');
            },
        );
    }

    /** @param array{filters:array<string,mixed>} $criteria */
    public function countFingerprint(array $criteria): string
    {
        return $this->countService->fingerprint($criteria['filters']);
    }

    public function flushPendingProjectionUpdates(): void
    {
        $this->projectionRefreshScheduler->flushPending();
    }

    /**
     * @param  array{cursor:?string,perPage:int,sortKey:string,sortDirection:string,filters:array<string,mixed>}  $criteria
     */
    public function encodeCursor(?Cursor $cursor, array $criteria): ?string
    {
        if ($cursor === null) {
            return null;
        }

        return $this->cursorCodec->encode($cursor->encode(), $this->cursorContextFingerprint($criteria));
    }

    /** @return Builder<Resource> */
    public function baseQuery(): Builder
    {
        $query = Resource::query();
        $this->joinListingProjection($query);

        return $query
            ->select([
                'resources.*',
                'listing.sort_doi as listing_sort_doi',
                'listing.main_title as listing_main_title',
                'listing.first_creator_sort as listing_first_creator_sort',
                'listing.curator_name as listing_curator_name',
                'listing.workflow_status as listing_workflow_status',
                'listing.workflow_status_rank as listing_workflow_status_rank',
                'listing.resource_type_sort as listing_resource_type_sort',
                'listing.resource_type_slug as listing_resource_type_slug',
                'listing.sort_year as listing_sort_year',
                'listing.created_sort as listing_created_sort',
                'listing.updated_sort as listing_updated_sort',
            ])
            ->with([
                'language:id,code,name',
                'landingPage' => function ($query): void {
                    $query->select([
                        'id',
                        'resource_id',
                        'doi_prefix',
                        'slug',
                        'template',
                        'external_domain_id',
                        'external_path',
                        'is_published',
                        'published_at',
                        'preview_token',
                    ])->with(['externalDomain:id,domain']);
                },
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'value', 'title_type_id'])
                        ->with(['titleType:id,name,slug'])
                        ->orderBy('id');
                },
                'rights:id,identifier,name',
                'creators' => function ($query): void {
                    $query->with(['creatorable'])->orderBy('position');
                },
            ]);
    }

    /**
     * Attach the mandatory non-IGSN listing projection to an existing Resource query.
     *
     * @param  Builder<Resource>  $query
     */
    public function joinListingProjection(Builder $query): void
    {
        $this->projectionRefreshScheduler->flushPending();
        $query
            ->join('resource_listing_projections as listing', 'listing.resource_id', '=', 'resources.id')
            ->where('listing.is_igsn', false);
    }

    /**
     * @param  Builder<Resource>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['resource_type'])) {
            $query->whereIn('listing.resource_type_slug', $filters['resource_type']);
        }

        if (! empty($filters['curator'])) {
            $query->whereIn('listing.curator_name', $filters['curator']);
        }

        if (! empty($filters['without_datacenter'])) {
            $query->whereNull('listing.datacenter_id');
        } elseif (isset($filters['datacenter_id'])) {
            $query->where('listing.datacenter_id', $filters['datacenter_id']);
        }

        if (! empty($filters['status'])) {
            $query->whereIn('listing.workflow_status', $filters['status']);
        }

        if (isset($filters['year_from'])) {
            $query->where('listing.publication_year', '>=', $filters['year_from']);
        }

        if (isset($filters['year_to'])) {
            $query->where('listing.publication_year', '<=', $filters['year_to']);
        }

        if (! empty($filters['search'])) {
            $query->where('listing.search_text', 'like', '%'.mb_strtolower((string) $filters['search']).'%');
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('resources.created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('resources.created_at', '<=', $filters['created_to']);
        }

        if (! empty($filters['updated_from'])) {
            $query->whereDate('resources.updated_at', '>=', $filters['updated_from']);
        }

        if (! empty($filters['updated_to'])) {
            $query->whereDate('resources.updated_at', '<=', $filters['updated_to']);
        }
    }

    /** @param Builder<Resource> $query */
    public function applySorting(Builder $query, string $sortKey, string $sortDirection): void
    {
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';
        $column = match ($sortKey) {
            'id' => 'resources.id',
            'doi' => 'listing_sort_doi',
            'title' => 'listing_main_title',
            'resourcetypegeneral' => 'listing_resource_type_sort',
            'first_author' => 'listing_first_creator_sort',
            'year' => 'listing_sort_year',
            'curator' => 'listing_curator_name',
            'publicstatus' => 'listing_workflow_status_rank',
            'created_at' => 'listing_created_sort',
            default => 'listing_updated_sort',
        };

        $query->orderBy($column, $direction);

        if ($column !== 'resources.id') {
            $query->orderBy('resources.id', $direction);
        }
    }

    /**
     * @param  array{perPage:int,sortKey:string,sortDirection:string,filters:array<string,mixed>}  $criteria
     */
    private function cursorContextFingerprint(array $criteria): string
    {
        return $this->countService->fingerprint([
            'filters' => $criteria['filters'],
            'cursor_sort_key' => $criteria['sortKey'],
            'cursor_sort_direction' => $criteria['sortDirection'],
            'cursor_per_page' => $criteria['perPage'],
        ]);
    }
}
