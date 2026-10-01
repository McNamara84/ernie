<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResourceTombstoneSyncService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncResourceTombstoneWithDataCiteJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $transitionId) {}

    public function uniqueId(): string
    {
        return (string) $this->transitionId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    public function handle(ResourceTombstoneSyncService $service): void
    {
        $service->sync($this->transitionId);
    }
}
