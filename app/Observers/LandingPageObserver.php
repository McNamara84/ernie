<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PortalCacheArea;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Services\PortalCacheInvalidationService;
use App\Services\ResourceCacheService;

/**
 * Observer for LandingPage model to track publish/depublish events
 * for OAI-PMH persistent deleted records support.
 */
class LandingPageObserver
{
    public function __construct(
        private readonly OaiPmhSetService $oaiPmhSetService,
        private readonly LandingPageRenderDataCacheService $renderDataCache,
        private readonly ResourceCacheService $resourceCacheService,
        private readonly PortalCacheInvalidationService $portalCacheInvalidationService,
    ) {}

    public function created(LandingPage $landingPage): void
    {
        $this->invalidateIgsnFamily($landingPage);

        if ($landingPage->is_published) {
            $this->resourceCacheService->invalidatePublishedResourceCounts();
            $this->schedulePortalInvalidation($landingPage, PortalCacheArea::all());
        }
    }

    public function deleting(LandingPage $landingPage): void
    {
        $landingPage->loadMissing('resource.resourceType');
    }

    /**
     * Handle the LandingPage "updated" event.
     *
     * Tracks depublishing (is_published: true → false) as a deletion in OAI-PMH.
     * Tracks republishing (is_published: false → true) by removing the deletion record.
     */
    public function updated(LandingPage $landingPage): void
    {
        $this->renderDataCache->forget($landingPage);
        $this->invalidateIgsnFamily($landingPage);

        if (! $landingPage->wasChanged('is_published')) {
            if ($landingPage->is_published && $landingPage->wasChanged([
                'doi_prefix',
                'template',
                'landing_page_template_id',
                'external_domain_id',
                'external_path',
                'published_at',
            ])) {
                $this->schedulePortalInvalidation($landingPage, [
                    PortalCacheArea::PAGE,
                    PortalCacheArea::MAP_PAYLOAD,
                ]);
            }

            return;
        }

        $this->resourceCacheService->invalidatePublishedResourceCounts();
        $this->schedulePortalInvalidation($landingPage, PortalCacheArea::all());

        $resource = $landingPage->resource;

        if ($resource->doi === null || $resource->doi === '') {
            return;
        }

        $oaiIdentifier = config('oaipmh.identifier_prefix').':'.$resource->doi;

        if (! $landingPage->is_published) {
            // Depublished → track as deleted in OAI-PMH (concurrency-safe)
            $resource->loadMissing('resourceType');
            $sets = $this->oaiPmhSetService->getSetsForResource($resource);

            OaiPmhDeletedRecord::updateOrCreate(
                ['oai_identifier' => $oaiIdentifier],
                [
                    'doi' => $resource->doi,
                    'datestamp' => now(),
                    'sets' => $sets,
                ],
            );
        } else {
            // Republished → remove from deleted records
            OaiPmhDeletedRecord::where('oai_identifier', $oaiIdentifier)->delete();
        }
    }

    public function deleted(LandingPage $landingPage): void
    {
        $this->renderDataCache->forget($landingPage);
        $this->invalidateIgsnFamily($landingPage);

        if ((bool) $landingPage->getOriginal('is_published')) {
            $this->resourceCacheService->invalidatePublishedResourceCounts();
            $this->schedulePortalInvalidation($landingPage, PortalCacheArea::all());
        }
    }

    private function invalidateIgsnFamily(LandingPage $landingPage): void
    {
        if ($landingPage->resource()->whereHas('igsnMetadata')->exists()) {
            $this->renderDataCache->forgetForIgsnFamilies([(int) $landingPage->resource_id]);
        }
    }

    /** @param iterable<PortalCacheArea> $areas */
    private function schedulePortalInvalidation(LandingPage $landingPage, iterable $areas): void
    {
        $landingPage->loadMissing('resource.resourceType');

        $this->portalCacheInvalidationService->schedule(
            [$this->portalCacheInvalidationService->scopeForResource($landingPage->resource)],
            $areas,
        );
    }
}
