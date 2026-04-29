<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Resource\LoadMoreResourcesRequest;
use App\Http\Resources\FilterOptionsResource;
use App\Http\Resources\ResourceListItemResource;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Resources\ResourceQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResourceFilterController extends Controller
{
    public function __construct(
        private readonly ResourceQueryBuilder $queryBuilder,
    ) {}

    /**
     * API endpoint for loading more resources (infinite scrolling).
     */
    public function loadMore(LoadMoreResourcesRequest $request): JsonResponse
    {
        $criteria = $request->toCriteria();
        $resources = $this->queryBuilder->paginate($criteria);

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = ResourceListItemResource::collection(collect($items))
            ->resolve($request);

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
        return (new FilterOptionsResource([
            'resource_types' => $this->loadResourceTypes(),
            'curators' => $this->loadCurators(),
            'year_range' => $this->loadYearRange(),
            // Single source of truth: the same allow-list that
            // ResolvesResourceListing uses for request validation, so the
            // filter UI cannot drift away from accepted query values.
            // Accessed via a consumer class because the constant lives on a
            // trait (PHPStan rejects `Trait::CONST` access).
            'statuses' => LoadMoreResourcesRequest::ALLOWED_STATUSES,
        ]))->response();
    }

    /**
     * @return array<int, array{name:string, slug:string}>
     */
    private function loadResourceTypes(): array
    {
        try {
            return ResourceType::query()
                ->where('slug', '!=', 'physical-object')
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
            $resourceQuery = Resource::query()
                ->whereDoesntHave('resourceType', function ($query): void {
                    $query->where('slug', 'physical-object');
                });
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

            if ($curatorIds->isEmpty()) {
                return [];
            }

            return User::query()
                ->whereIn('id', $curatorIds->all())
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values()
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
            if (Schema::hasColumn('resources', 'publication_year')) {
                // Mirror ResourceQueryBuilder::baseQuery() — exclude Physical Object
                // resources (IGSNs) so the /resources year range filter cannot be
                // skewed by IGSN publication years.
                $yearQuery = Resource::query()
                    ->whereDoesntHave('resourceType', function ($query): void {
                        $query->where('slug', 'physical-object');
                    });

                $yearMin = (clone $yearQuery)->min('publication_year');
                $yearMax = (clone $yearQuery)->max('publication_year');
            }
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
