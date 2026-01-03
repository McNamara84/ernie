<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Resource;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;

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
     * Also syncs DOI changes to associated landing page and invalidates
     * landing page caches when the DOI changes.
     */
    public function updated(Resource $resource): void
    {
        $this->cacheService->invalidateResourceCache($resource->id);

        // Sync DOI to landing page if DOI was changed during this save.
        // Use wasChanged() instead of isDirty() because observers run AFTER the model
        // is saved, at which point the model is no longer dirty.
        // Use exists() query to avoid loading the entire model and prevent N+1 issues.
        // Note: If DOI is removed (set to null), the landing page's doi_prefix will be
        // set to null, effectively transitioning from DOI-based URL to draft URL format.
        // Business logic should typically prevent DOI removal for published landing pages,
        // but this sync ensures data consistency regardless.
        if ($resource->wasChanged('doi') && $resource->landingPage()->exists()) {
            // Get the old DOI to invalidate any caches keyed by old DOI+slug
            $oldDoi = $resource->getOriginal('doi');

            $resource->landingPage()->update([
                'doi_prefix' => $resource->doi,
            ]);

            // Invalidate landing page caches for both old and new DOI-based URLs.
            // This ensures stale content under the old DOI key is cleared, and
            // any cached 404s for the new DOI are also cleared.
            $landingPage = $resource->landingPage;
            if ($landingPage !== null) {
                // Clear cache for old DOI-based URL (if there was an old DOI)
                if ($oldDoi !== null) {
                    Cache::forget("landing-page.{$oldDoi}.{$landingPage->slug}");
                }
                // Clear cache for new DOI-based URL (if there is a new DOI)
                if ($resource->doi !== null) {
                    Cache::forget("landing-page.{$resource->doi}.{$landingPage->slug}");
                }
                // Also clear by resource ID
                Cache::forget("landing-page.{$resource->id}");
            }
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
