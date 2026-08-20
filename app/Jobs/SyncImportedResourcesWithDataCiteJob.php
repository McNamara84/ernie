<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Resource;
use App\Services\DataCiteSyncService;
use App\Services\ImportProgressService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncImportedResourcesWithDataCiteJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /** @param list<int> $resourceIds */
    public function __construct(
        private readonly string $importType,
        private readonly string $importId,
        private readonly array $resourceIds,
    ) {}

    public function handle(DataCiteSyncService $syncService, ImportProgressService $progressService): void
    {
        // This is the hard safety boundary: no import-triggered write may reach
        // either DataCite API while test mode is enabled.
        if (config('datacite.test_mode') !== false) {
            return;
        }

        foreach ($this->resourceIds as $resourceId) {
            if ($this->batch()?->cancelled() === true) {
                return;
            }

            $progress = $progressService->get($this->importType, $this->importId);
            if (($progress['status'] ?? null) === 'cancelled') {
                return;
            }

            $resource = Resource::query()->with('landingPage')->find($resourceId);

            if ($resource === null) {
                $progressService->recordSyncFailure(
                    $this->importType,
                    $this->importId,
                    $resourceId,
                    null,
                    'The imported resource no longer exists.',
                );

                continue;
            }

            try {
                $result = $syncService->syncIfRegistered($resource);

                if ($result->hasFailed()) {
                    $progressService->recordSyncFailure(
                        $this->importType,
                        $this->importId,
                        $resourceId,
                        $result->doi,
                        $result->errorMessage ?? 'DataCite synchronization failed.',
                    );
                } else {
                    $progressService->recordSyncSuccess($this->importType, $this->importId, $resourceId);
                }
            } catch (\Throwable $exception) {
                Log::error('Unexpected import DataCite sync failure', [
                    'import_type' => $this->importType,
                    'import_id' => $this->importId,
                    'resource_id' => $resourceId,
                    'doi' => $resource->doi,
                    'error' => $exception->getMessage(),
                ]);

                $progressService->recordSyncFailure(
                    $this->importType,
                    $this->importId,
                    $resourceId,
                    $resource->doi,
                    $exception->getMessage(),
                );
            }
        }
    }
}
