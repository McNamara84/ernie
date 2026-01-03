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
    ) {}

    /**
     * Handle the Resource "created" event.
     *
     * Invalidates all resource caches since the new resource
     * will appear in list views.
     */
    public function created(Resource $resource): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }

    /**
     * Handle the Resource "updated" event.
     *
     * Only invalidates the specific resource cache and list caches.
     * This is handled by invalidateResourceCache which calls
     * invalidateAllResourceCaches internally.
     *
     * Also syncs DOI changes to associated landing page.
     */
    public function updated(Resource $resource): void
    {
        $this->cacheService->invalidateResourceCache($resource->id);

        // Sync DOI to landing page if DOI changed
        // Use exists() query to avoid loading the entire model and prevent N+1 issues
        if ($resource->isDirty('doi') && $resource->landingPage()->exists()) {
            $resource->landingPage()->update([
                'doi_prefix' => $resource->doi,
            ]);
        }
    }

    /**
     * Handle the Resource "deleted" event.
     *
     * Invalidates all resource caches since the resource
     * will be removed from list views.
     */
    public function deleted(Resource $resource): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }

    /**
     * Handle the Resource "forceDeleted" event.
     *
     * Invalidates all resource caches since the resource
     * will be removed from list views.
     */
    public function forceDeleted(Resource $resource): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }
}
