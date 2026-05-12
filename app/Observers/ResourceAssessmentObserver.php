<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CacheKey;
use App\Models\ResourceAssessment;
use Illuminate\Support\Facades\Cache;

class ResourceAssessmentObserver
{
    private const VERSION_CACHE_SUFFIX = 'version';

    private const VERSION_LOCK_SUFFIX = 'version-lock';

    private const VERSION_LOCK_TTL_SECONDS = 5;

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
        $versionKey = CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key(self::VERSION_CACHE_SUFFIX);
        $lockKey = CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key(self::VERSION_LOCK_SUFFIX);

        Cache::lock($lockKey, self::VERSION_LOCK_TTL_SECONDS)
            ->block(self::VERSION_LOCK_TTL_SECONDS, function () use ($versionKey): void {
                $currentVersion = max(1, (int) Cache::get($versionKey, 1));

                Cache::forever($versionKey, $currentVersion + 1);
            });
    }
}