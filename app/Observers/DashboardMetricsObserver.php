<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CacheKey;
use App\Models\Affiliation;

final class DashboardMetricsObserver
{
    public function saved(Affiliation $affiliation): void
    {
        CacheKey::DASHBOARD_METRICS->forget();
    }

    public function deleted(Affiliation $affiliation): void
    {
        CacheKey::DASHBOARD_METRICS->forget();
    }
}
