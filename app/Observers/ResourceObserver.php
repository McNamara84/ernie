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
    ) {
    }

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
     * DOI Syncing Scope: This observer syncs DOI changes to existing landing pages
     * when a resource's DOI is modified AFTER a landing page already exists.
     * It does NOT handle initial DOI population when a landing page is first created -
     * that is handled by the LandingPage model's boot() method which reads the
     * resource's DOI during the 'creating' event.
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

            // Use a database transaction with row-level locking to prevent race conditions.
            // If two processes update the resource DOI simultaneously, both could pass the
            // wasChanged() check and attempt to update the landing page. The lockForUpdate()
            // ensures only one process can read and update the landing page at a time,
            // preventing inconsistent state.
            //
            // DEADLOCK PREVENTION: The current schema enforces one landing page per resource
            // (resource_id unique constraint), so deadlocks cannot occur from this observer.
            // If the schema evolves to allow multiple landing pages per resource, the lock
            // acquisition order should be documented here. MySQL/MariaDB will auto-detect
            // deadlocks and abort one transaction after innodb_lock_wait_timeout (default 50s).
            //
            // If deadlocks become an issue, consider:
            // 1. Adding explicit lock ordering (e.g., always lock by ascending landing_page.id)
            // 2. Using DB::statement('SET innodb_lock_wait_timeout = 5') before the transaction
            // 3. Implementing retry logic for deadlock exceptions (SQLSTATE 40001)
            $landingPage = \Illuminate\Support\Facades\DB::transaction(function () use ($resource, $oldDoi) {
                // Fetch with row-level lock to prevent concurrent updates.
                // This SELECT ... FOR UPDATE blocks other transactions trying to read
                // the same row until this transaction commits or rolls back.
                //
                // Note: We lock the entire row (no select()) to ensure all columns are
                // available during the locked operation. This prevents issues if the model
                // accesses other attributes during save() (e.g., timestamps, observers).
                $landingPage = $resource->landingPage()
                    ->lockForUpdate()
                    ->first();

                if ($landingPage !== null) {
                    if ($landingPage->is_published) {
                        // Log warning for DOI changes on published landing pages.
                        // This helps operators identify potential broken link issues.
                        //
                        // NOTE: Prevention of unauthorized DOI changes is enforced by
                        // ResourcePolicy::changeDoi() which only allows Admins to change
                        // DOIs on resources with published landing pages. This observer
                        // is purely for logging/monitoring - it does NOT prevent the change.
                        // The policy check happens before the request reaches this point.
                        // See: app/Policies/ResourcePolicy.php::changeDoi()
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

                    // Update doi_prefix on the locked instance directly.
                    // Using the relationship's update() method would execute a new query
                    // that might bypass the lock. By updating the locked model directly,
                    // we ensure the lock is maintained throughout the update operation.
                    $landingPage->doi_prefix = $resource->doi;
                    $landingPage->save();
                }

                return $landingPage;
            });

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
