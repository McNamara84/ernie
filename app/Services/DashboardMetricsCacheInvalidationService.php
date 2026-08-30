<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class DashboardMetricsCacheInvalidationService
{
    private bool $invalidationScheduled = false;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

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
        CacheKey::DASHBOARD_METRICS->forget();
    }
}
