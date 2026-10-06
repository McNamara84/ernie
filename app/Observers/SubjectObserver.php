<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PortalCacheArea;
use App\Models\Subject;
use App\Services\OaiPmh\OaiPmhDatestampService;
use App\Services\PortalCacheInvalidationService;

class SubjectObserver
{
    public function __construct(
        private readonly PortalCacheInvalidationService $cacheInvalidationService,
        private readonly OaiPmhDatestampService $datestampService,
    ) {}

    public function created(Subject $subject): void
    {
        $this->datestampService->touchResource((int) $subject->resource_id);
        $subject->unsetRelation('resource');
    }

    public function updated(Subject $subject): void
    {
        if ($subject->wasChanged([
            'resource_id', 'value', 'language', 'subject_scheme', 'scheme_uri', 'value_uri', 'classification_code', 'breadcrumb_path',
        ])) {
            $this->datestampService->touchResource((int) $subject->resource_id);
            if ($subject->wasChanged('resource_id')) {
                $this->datestampService->touchResource((int) $subject->getOriginal('resource_id'));
            }
            $subject->unsetRelation('resource');
        }
    }

    public function saved(Subject $subject): void
    {
        $this->schedule($subject);
    }

    public function deleted(Subject $subject): void
    {
        if ($subject->getKey() !== null) {
            $this->datestampService->touchResource((int) $subject->resource_id);
            $subject->unsetRelation('resource');
        }
        $this->schedule($subject);
    }

    private function schedule(Subject $subject): void
    {
        $this->cacheInvalidationService->scheduleForResourceId((int) $subject->resource_id, [
            PortalCacheArea::PAGE,
            PortalCacheArea::COUNT,
            PortalCacheArea::KEYWORDS,
            PortalCacheArea::IGSN_FACETS,
            PortalCacheArea::MAP_PAYLOAD,
            PortalCacheArea::MAP_EXTENT,
        ]);
    }
}
