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
     *
     * DOI Change Warning: Changing a DOI for a resource with a published landing page
     * will change the landing page's public URL. This can break existing citations,
     * bookmarks, and external links. The old URL will return 404 without redirect.
     *
     * Prevention: The ResourcePolicy::changeDoi() method should be used by controllers
     * to prevent unauthorized DOI changes on published resources. Only Admins are
     * allowed to make this breaking change. See ResourcePolicy for implementation.
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
        if ($resource->wasChanged('doi') && $resource->landingPage()->exists()) {
            // Get the old DOI to invalidate any caches keyed by old DOI+slug
            $oldDoi = $resource->getOriginal('doi');

            // Fetch only the fields needed for cache invalidation and status check.
            // This avoids loading the entire model when we only need a few columns.
            // IMPORTANT: The select must include 'is_published' because we check it below
            // to determine whether to log a warning about DOI changes on published pages.
            // If is_published is removed from this select, the warning logic will break.
            $landingPage = $resource->landingPage()
                ->select(['id', 'resource_id', 'doi_prefix', 'slug', 'is_published'])
                ->first();

            if ($landingPage !== null && $landingPage->is_published) {
                // Log warning for DOI changes on published landing pages.
                // This helps operators identify potential broken link issues.
                // The actual prevention should happen in controllers/policies.
                \Illuminate\Support\Facades\Log::warning(
                    'ResourceObserver: DOI changed for resource with published landing page',
                    [
                        'resource_id' => $resource->id,
                        'old_doi' => $oldDoi,
                        'new_doi' => $resource->doi,
                        'landing_page_id' => $landingPage->id,
                        'old_url_will_break' => true,
                    ]
                );
            }

            // Update doi_prefix directly on the relation (not on the selected model)
            $resource->landingPage()->update([
                'doi_prefix' => $resource->doi,
            ]);

            // Invalidate landing page caches for both old and new DOI-based URLs.
            // This ensures stale content under the old DOI key is cleared, and
            // any cached 404s for the new DOI are also cleared.
            // Also clear using cache tags if available for more thorough cleanup.
            // Note: We use the slug from the originally selected model since it's immutable.
            if ($landingPage !== null) {
                // Clear cache for old DOI-based URL (if there was an old DOI)
                if ($oldDoi !== null) {
                    Cache::forget("landing-page.{$oldDoi}.{$landingPage->slug}");
                }
                // Clear cache for new DOI-based URL (if there is a new DOI)
                if ($resource->doi !== null) {
                    Cache::forget("landing-page.{$resource->doi}.{$landingPage->slug}");
                }
                // Also clear by resource ID (covers draft-style URLs)
                Cache::forget("landing-page.{$resource->id}");
                // Clear by landing page ID for any ID-based cache keys
                Cache::forget("landing-page.{$landingPage->id}");
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
