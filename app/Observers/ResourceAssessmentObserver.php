<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CacheKey;
use App\Models\ResourceAssessment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
        $lock = Cache::lock($lockKey, self::VERSION_LOCK_TTL_SECONDS);

        if ($lock->get()) {
            try {
                $this->storeNextVersion($versionKey);

                return;
            } finally {
                $lock->release();
            }
        }

        Log::warning('Assessment summary cache version lock could not be acquired immediately, falling back to best-effort increment.', [
            'cache_key' => $versionKey,
            'lock_key' => $lockKey,
        ]);

        $this->bestEffortBumpVersion($versionKey);
    }

    private function storeNextVersion(string $versionKey): void
    {
        $currentVersion = max(1, (int) Cache::get($versionKey, 1));

        Cache::forever($versionKey, $currentVersion + 1);
    }

    private function bestEffortBumpVersion(string $versionKey): void
    {
        Cache::add($versionKey, 1, now()->addDay());

        $incrementedVersion = Cache::increment($versionKey);

        if ($incrementedVersion !== false) {
            return;
        }

        $this->storeNextVersion($versionKey);
    }
}