<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Resource;
use App\Services\ResourceCacheService;

/**
 * Observer for Resource model to handle cache invalidation.
 *
 * This observer automatically invalidates cached resource data
 * whenever a resource is created, updated, or deleted.
 */
class ResourceObserver
{
    /**
     * Create a new observer instance.
     */
    public function __construct(
        private readonly ResourceCacheService $cacheService
    ) {
    }

    /**
     * Handle the Resource "created" event.
     */
    public function created(Resource $resource): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }

    /**
     * Handle the Resource "updated" event.
     */
    public function updated(Resource $resource): void
    {
        $this->cacheService->invalidateResourceCache($resource->id);
    }

    /**
     * Handle the Resource "deleted" event.
     */
    public function deleted(Resource $resource): void
    {
        $this->cacheService->invalidateResourceCache($resource->id);
    }

    /**
     * Handle the Resource "forceDeleted" event.
     */
    public function forceDeleted(Resource $resource): void
    {
        $this->cacheService->invalidateResourceCache($resource->id);
    }
}
