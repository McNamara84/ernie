<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Enums\CacheKey;
use Illuminate\Database\DatabaseManager;
use Throwable;

class ResourceFilterOptionsCacheInvalidationService
{
    private bool $invalidationScheduled = false;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    /** Invalidate the cached filter labels only after the surrounding write commits. */
    public function scheduleAfterCommit(): void
    {
        if ($this->invalidationScheduled) {
            return;
        }

        try {
            $connection = $this->databaseManager->connection();

            if ($connection->transactionLevel() === 0) {
                $this->invalidate();

                return;
            }

            $this->invalidationScheduled = true;
            $connection->afterCommit(function (): void {
                $this->invalidationScheduled = false;
                $this->invalidate();
            });
            $connection->afterRollBack(function (): void {
                $this->invalidationScheduled = false;
            });
        } catch (Throwable) {
            $this->invalidationScheduled = false;
            $this->invalidate();
        }
    }

    private function invalidate(): void
    {
        CacheKey::RESOURCE_FILTER_OPTIONS->forget();
    }
}
