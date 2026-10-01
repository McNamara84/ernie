<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResourceTombstoneSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncResourceTombstoneWithDataCiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $transitionId) {}

    public function handle(ResourceTombstoneSyncService $service): void
    {
        $service->sync($this->transitionId);
    }
}
