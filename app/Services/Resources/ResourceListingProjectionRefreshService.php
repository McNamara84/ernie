<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ResourceListingProjectionRefreshService
{
    /** @var array<int, true> */
    private array $resourceIds = [];

    private bool $scheduled = false;

    public function __construct(
        private readonly ResourceListingProjectorService $projector,
        private readonly ResourceCacheService $resourceCacheService,
        private readonly ListingCountService $listingCountService,
    ) {}

    public function schedule(int $resourceId): void
    {
        $this->scheduleMany([$resourceId]);
    }

    /** @param iterable<int> $resourceIds */
    public function scheduleMany(iterable $resourceIds): void
    {
        foreach ($resourceIds as $resourceId) {
            if ($resourceId > 0) {
                $this->resourceIds[$resourceId] = true;
            }
        }

        if ($this->resourceIds === [] || $this->scheduled) {
            return;
        }

        $manager = DB::getFacadeRoot();
        if (! $manager instanceof DatabaseManager) {
            $this->flush();

            return;
        }

        try {
            $connection = $manager->connection();
            if ($connection->transactionLevel() === 0) {
                $this->flush();

                return;
            }

            $this->scheduled = true;
            $connection->afterCommit(fn () => $this->flush());
            $connection->afterRollBack(function (): void {
                $this->scheduled = false;
                $this->resourceIds = [];
            });
        } catch (Throwable) {
            $this->scheduled = false;
            $this->flush();
        }
    }

    public function forget(int $resourceId): void
    {
        unset($this->resourceIds[$resourceId]);
        $this->projector->forget($resourceId);
    }

    /** Make pending writes visible to an in-transaction read in the same process. */
    public function flushPending(): void
    {
        if ($this->resourceIds !== []) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        $ids = array_keys($this->resourceIds);
        $this->resourceIds = [];
        $this->scheduled = false;
        $this->projector->refreshMany($ids);
        $this->resourceCacheService->invalidateAllResourceCaches();
        $this->listingCountService->scheduleInternalInvalidationAfterCommit();
    }
}
