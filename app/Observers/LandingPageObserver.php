<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Services\OaiPmh\OaiPmhSetService;

/**
 * Observer for LandingPage model to track publish/depublish events
 * for OAI-PMH persistent deleted records support.
 */
class LandingPageObserver
{
    public function __construct(
        private readonly OaiPmhSetService $oaiPmhSetService,
    ) {}

    /**
     * Handle the LandingPage "updated" event.
     *
     * Tracks depublishing (is_published: true → false) as a deletion in OAI-PMH.
     * Tracks republishing (is_published: false → true) by removing the deletion record.
     */
    public function updated(LandingPage $landingPage): void
    {
        if (! $landingPage->wasChanged('is_published')) {
            return;
        }

        $resource = $landingPage->resource;

        if ($resource->doi === null || $resource->doi === '') {
            return;
        }

        $oaiIdentifier = config('oaipmh.identifier_prefix') . ':' . $resource->doi;

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
}
