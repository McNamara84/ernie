<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Affiliation;
use App\Services\DashboardMetricsCacheInvalidationService;

final class DashboardMetricsObserver
{
    public function __construct(
        private readonly DashboardMetricsCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function saved(Affiliation $affiliation): void
    {
        $this->cacheInvalidationService->scheduleAfterCommit();
    }

    public function deleted(Affiliation $affiliation): void
    {
        $this->cacheInvalidationService->scheduleAfterCommit();
    }
}
