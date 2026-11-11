<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Resource;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataEmbargoReleaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retry only access metadata of an existing registration, never create/publish. */
final class SyncDataEmbargoAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $resourceId, public readonly bool $testMode) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(DataEmbargoReleaseService $service, DataCiteRequestLimiter $limiter): void
    {
        $resource = Resource::find($this->resourceId);
        if ($resource === null || ! $resource->data_embargo_sync_pending) {
            return;
        }
        $service->synchronize($resource, new DataCiteMemberApiClient($limiter, $this->testMode));
    }
}
