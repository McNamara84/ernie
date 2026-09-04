<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PortalCacheArea;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\ResourceDate;
use App\Services\PortalCacheInvalidationService;
use Illuminate\Database\Eloquent\Model;

/** Invalidates public portal data when a resource-owned relation changes. */
final class PortalResourceDependencyObserver
{
    public function __construct(
        private readonly PortalCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function saved(Model $model): void
    {
        if ($model instanceof IgsnMetadata
            && ! $model->wasRecentlyCreated
            && ! $model->wasChanged(['sample_type', 'material'])) {
            return;
        }

        $this->schedule($model);
    }

    public function deleted(Model $model): void
    {
        $this->schedule($model);
    }

    private function schedule(Model $model): void
    {
        $resourceId = $model->getAttribute('resource_id');
        if (! is_numeric($resourceId)) {
            return;
        }

        $this->cacheInvalidationService->scheduleForResourceId(
            (int) $resourceId,
            $this->areasFor($model),
        );
    }

    /** @return list<PortalCacheArea> */
    private function areasFor(Model $model): array
    {
        return match (true) {
            $model instanceof GeoLocation => [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::IGSN_FACETS,
                PortalCacheArea::MAP_PAYLOAD,
                PortalCacheArea::MAP_EXTENT,
            ],
            $model instanceof ResourceDate => [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::TEMPORAL_RANGE,
                PortalCacheArea::IGSN_FACETS,
                PortalCacheArea::MAP_PAYLOAD,
                PortalCacheArea::MAP_EXTENT,
            ],
            $model instanceof IgsnMetadata,
            $model instanceof IgsnClassification,
            $model instanceof IgsnGeologicalAge,
            $model instanceof IgsnGeologicalUnit => [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::IGSN_FACETS,
                PortalCacheArea::MAP_PAYLOAD,
                PortalCacheArea::MAP_EXTENT,
            ],
            default => [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::IGSN_FACETS,
            ],
        };
    }
}
