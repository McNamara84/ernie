<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Affiliation;
use App\Models\ResourceCreator;
use App\Models\ResourceListingProjection;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Support\Traits\ChecksCacheTagging;

final class DashboardMetricsService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly ResourceCacheService $resourceCacheService,
        private readonly ResourceListingProjectionRefreshService $projectionRefreshScheduler,
    ) {}

    /**
     * @return array{
     *   dataResourceCount:int,
     *   igsnCount:int,
     *   dataInstitutionCount:int,
     *   igsnInstitutionCount:int,
     *   draftCount:int
     * }
     */
    public function metrics(): array
    {
        $this->projectionRefreshScheduler->flushPending();

        return $this->getCacheInstance(CacheKey::DASHBOARD_METRICS->tags())->remember(
            CacheKey::DASHBOARD_METRICS->key(),
            CacheKey::DASHBOARD_METRICS->ttl(),
            fn (): array => $this->resolve(),
        );
    }

    /**
     * @return array{
     *   dataResourceCount:int,
     *   igsnCount:int,
     *   dataInstitutionCount:int,
     *   igsnInstitutionCount:int,
     *   draftCount:int
     * }
     */
    private function resolve(): array
    {
        $physicalObjectTypeId = $this->resourceCacheService->getPhysicalObjectTypeId();
        $applyNonIgsnFilter = static function ($query) use ($physicalObjectTypeId): void {
            if ($physicalObjectTypeId === null) {
                return;
            }

            $query->where(function ($resourceQuery) use ($physicalObjectTypeId): void {
                $resourceQuery->whereNull('resource_type_id')
                    ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
            });
        };

        $dataInstitutionCount = Affiliation::query()
            ->whereNotNull('identifier')
            ->where('identifier_scheme', 'ROR')
            ->whereHasMorph('affiliatable', [ResourceCreator::class], function ($query) use ($applyNonIgsnFilter): void {
                $query->whereHas('resource', function ($resourceQuery) use ($applyNonIgsnFilter): void {
                    $applyNonIgsnFilter($resourceQuery);
                });
            })
            ->distinct('identifier')
            ->count('identifier');

        $igsnInstitutionCount = $physicalObjectTypeId === null
            ? 0
            : Affiliation::query()
                ->whereNotNull('identifier')
                ->where('identifier_scheme', 'ROR')
                ->whereHasMorph('affiliatable', [ResourceCreator::class], function ($query) use ($physicalObjectTypeId): void {
                    $query->whereHas('resource', function ($resourceQuery) use ($physicalObjectTypeId): void {
                        $resourceQuery->where('resource_type_id', $physicalObjectTypeId);
                    });
                })
                ->distinct('identifier')
                ->count('identifier');

        return [
            'dataResourceCount' => $this->resourceCacheService->getDataResourceCount($physicalObjectTypeId),
            'igsnCount' => $this->resourceCacheService->getIgsnCount($physicalObjectTypeId),
            'dataInstitutionCount' => $dataInstitutionCount,
            'igsnInstitutionCount' => $igsnInstitutionCount,
            'draftCount' => ResourceListingProjection::query()
                ->where('is_igsn', false)
                ->where('is_dashboard_draft', true)
                ->count(),
        ];
    }
}
