<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Enums\CacheKey;
use Illuminate\Database\DatabaseManager;
use Throwable;

class AssistanceDatacenterOptionsCacheInvalidationService
{
    private bool $invalidationScheduled = false;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    /** Invalidate only after the surrounding write is visible to other connections. */
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
        CacheKey::ASSISTANCE_DATACENTER_OPTIONS->forget();
    }
}
