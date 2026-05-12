<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AssessmentAverageSummaryVersionService
{
    private const VERSION_CACHE_SUFFIX = 'version';

    private const VERSION_LOCK_SUFFIX = 'version-lock';

    private const VERSION_LOCK_TTL_SECONDS = 5;

    public function current(): int
    {
        return max(1, (int) Cache::get($this->versionKey(), 1));
    }

    public function bump(): void
    {
        $lock = Cache::lock($this->lockKey(), self::VERSION_LOCK_TTL_SECONDS);

        if ($lock->get()) {
            try {
                $this->storeNextVersion();

                return;
            } finally {
                $lock->release();
            }
        }

        Log::warning('Assessment summary cache version lock could not be acquired immediately, falling back to best-effort increment.', [
            'cache_key' => $this->versionKey(),
            'lock_key' => $this->lockKey(),
        ]);

        $this->bestEffortBumpVersion();
    }

    private function storeNextVersion(): void
    {
        Cache::forever($this->versionKey(), $this->current() + 1);
    }

    private function bestEffortBumpVersion(): void
    {
        $versionKey = $this->versionKey();

        Cache::add($versionKey, 1, now()->addDay());

        $incrementedVersion = Cache::increment($versionKey);

        if ($incrementedVersion !== false) {
            return;
        }

        $this->storeNextVersion();
    }

    private function versionKey(): string
    {
        return CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key(self::VERSION_CACHE_SUFFIX);
    }

    private function lockKey(): string
    {
        return CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key(self::VERSION_LOCK_SUFFIX);
    }
}
