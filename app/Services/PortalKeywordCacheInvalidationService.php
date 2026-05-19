<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PortalKeywordCacheInvalidationService
{
    private bool $invalidateCacheScheduled = false;

    public function __construct(
        private readonly KeywordSuggestionService $keywordSuggestionService,
    ) {}

    public function scheduleAfterCommit(): void
    {
        if ($this->invalidateCacheScheduled) {
            return;
        }

        $this->invalidateCacheScheduled = true;

        DB::afterCommit(function (): void {
            $this->invalidateCacheScheduled = false;
            $this->keywordSuggestionService->invalidateCache();
        });

        DB::afterRollBack(function (): void {
            $this->invalidateCacheScheduled = false;
        });
    }
}