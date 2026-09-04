<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PortalCacheArea;
use App\Models\Subject;
use App\Services\PortalCacheInvalidationService;

class SubjectObserver
{
    public function __construct(
        private readonly PortalCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function saved(Subject $subject): void
    {
        $this->schedule($subject);
    }

    public function deleted(Subject $subject): void
    {
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
