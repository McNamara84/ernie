<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Http\Requests\Resource\LoadMoreResourcesRequest;
use App\Http\Resources\FilterOptionsResource;
use App\Http\Resources\ResourceListItemResource;
use App\Models\Datacenter;
use App\Models\ResourceListingProjection;
use App\Models\ResourceType;
use App\Services\Resources\ResourceQueryBuilder;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResourceFilterController extends Controller
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly ResourceQueryBuilder $queryBuilder,
    ) {}

    /**
     * API endpoint for loading more resources (infinite scrolling).
     */
    public function loadMore(LoadMoreResourcesRequest $request): JsonResponse
    {
        $criteria = $request->toCriteria();
        $resources = $this->queryBuilder->cursorPaginate($criteria);

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $itemCount = count($items);
        $resourcesData = ResourceListItemResource::collection(collect($items))
            ->resolve($request);

        return response()->json([
            'resources' => $resourcesData,
            'pagination' => [
                'per_page' => $resources->perPage(),
                'from' => $itemCount === 0 ? null : 1,
                'to' => $itemCount === 0 ? null : $itemCount,
                'has_more' => $resources->hasMorePages(),
                'next_cursor' => $this->queryBuilder->encodeCursor($resources->nextCursor(), $criteria),
                'previous_cursor' => $this->queryBuilder->encodeCursor($resources->previousCursor(), $criteria),
            ],
            'sort' => [
                'key' => $criteria['sortKey'],
                'direction' => $criteria['sortDirection'],
            ],
        ]);
    }

    /**
     * API endpoint to get available filter options.
     *
     * Note: Physical Object resources are excluded from filters because they
     * have their own dedicated page at /igsns.
     */
    public function getFilterOptions(): JsonResponse
    {
        $this->queryBuilder->flushPendingProjectionUpdates();

        $options = $this->getCacheInstance(CacheKey::RESOURCE_FILTER_OPTIONS->tags())->remember(
            CacheKey::RESOURCE_FILTER_OPTIONS->key(),
            CacheKey::RESOURCE_FILTER_OPTIONS->ttl(),
            fn (): array => [
                'resource_types' => $this->loadResourceTypes(),
                'datacenters' => $this->loadDatacenters(),
                'curators' => $this->loadCurators(),
                'year_range' => $this->loadYearRange(),
                // Single source of truth: the same allow-list used for request
                // validation, so the filter UI cannot drift from accepted values.
                'statuses' => LoadMoreResourcesRequest::ALLOWED_STATUSES,
            ],
        );

        return (new FilterOptionsResource($options))->response();
    }

    /**
     * Return datacenters assigned to at least one non-IGSN resource.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private function loadDatacenters(): array
    {
        try {
            return Datacenter::query()
                ->whereIn('id', ResourceListingProjection::query()
                    ->where('is_igsn', false)
                    ->whereNotNull('datacenter_id')
                    ->select('datacenter_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Datacenter $datacenter): array => [
                    'id' => $datacenter->id,
                    'name' => $datacenter->name,
                ])
                ->all();
        } catch (Throwable $e) {
            Log::warning('Failed to load datacenter filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array{name:string, slug:string}>
     */
    private function loadResourceTypes(): array
    {
        try {
            return ResourceType::query()
                ->whereIn('id', ResourceListingProjection::query()
                    ->where('is_igsn', false)
                    ->whereNotNull('resource_type_id')
                    ->select('resource_type_id'))
                ->orderBy('name')
                ->get(['name', 'slug'])
                ->map(fn ($type) => ['name' => $type->name, 'slug' => $type->slug])
                ->all();
        } catch (Throwable $e) {
            Log::warning('Failed to load resource type filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function loadCurators(): array
    {
        try {
            // Mirror ResourceQueryBuilder::baseQuery() — exclude Physical Object
            // resources (IGSNs), which live on their own /igsns page and must not
            // leak into the /resources curator filter.
            return ResourceListingProjection::query()
                ->where('is_igsn', false)
                ->where('curator_name', '!=', '')
                ->distinct()
                ->orderBy('curator_name')
                ->pluck('curator_name')
                ->all();
        } catch (Throwable $e) {
            Log::warning('Failed to load curator filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{min:int, max:int}
     */
    private function loadYearRange(): array
    {
        $yearMin = null;
        $yearMax = null;

        try {
            // Mirror ResourceQueryBuilder::baseQuery() — exclude Physical Object
            // resources (IGSNs) so the /resources year range filter cannot be
            // skewed by IGSN publication years.
            $yearQuery = ResourceListingProjection::query()->where('is_igsn', false);

            $yearMin = (clone $yearQuery)->min('publication_year');
            $yearMax = (clone $yearQuery)->max('publication_year');
        } catch (Throwable $e) {
            Log::warning('Failed to load year range filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Keep API shape stable when there are no resources yet.
        if ($yearMin === null || $yearMax === null) {
            $currentYear = (int) now()->year;
            $yearMin = $currentYear;
            $yearMax = $currentYear;
        }

        return [
            'min' => (int) $yearMin,
            'max' => (int) $yearMax,
        ];
    }
}
