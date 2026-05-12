<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CacheKey;
use App\Models\ResourceAssessment;
use Illuminate\Support\Facades\Cache;

class ResourceAssessmentObserver
{
    public function saved(ResourceAssessment $resourceAssessment): void
    {
        $this->invalidateAssessmentCaches();
    }

    public function deleted(ResourceAssessment $resourceAssessment): void
    {
        $this->invalidateAssessmentCaches();
    }

    public function forceDeleted(ResourceAssessment $resourceAssessment): void
    {
        $this->invalidateAssessmentCaches();
    }

    private function invalidateAssessmentCaches(): void
    {
        $versionKey = CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version');
        $currentVersion = max(1, (int) Cache::get($versionKey, 1));

        Cache::forever($versionKey, $currentVersion + 1);
    }
}