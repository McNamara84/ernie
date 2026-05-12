<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CacheKey;
use App\Models\ResourceAssessment;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Cache;

class ResourceAssessmentObserver
{
    use ChecksCacheTagging;

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
        if ($this->supportsTagging()) {
            Cache::tags(['assessments'])->flush();

            return;
        }

        CacheKey::ASSESSMENT_AVERAGE_SUMMARY->forget();
    }
}