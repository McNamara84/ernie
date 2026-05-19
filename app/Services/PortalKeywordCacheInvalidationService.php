<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        $databaseManager = DB::getFacadeRoot();

        if (! $databaseManager instanceof DatabaseManager) {
            $this->keywordSuggestionService->invalidateCache();

            return;
        }

        try {
            $connection = $databaseManager->connection();

            if ($connection->transactionLevel() === 0) {
                $this->keywordSuggestionService->invalidateCache();

                return;
            }

            $this->invalidateCacheScheduled = true;

            $connection->afterCommit(function (): void {
                $this->invalidateCacheScheduled = false;
                $this->keywordSuggestionService->invalidateCache();
            });

            $connection->afterRollBack(function (): void {
                $this->invalidateCacheScheduled = false;
            });
        } catch (Throwable) {
            $this->invalidateCacheScheduled = false;
            $this->keywordSuggestionService->invalidateCache();
        }
    }
}