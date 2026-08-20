<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportedResourceDataCiteSyncDispatcherService
{
    private const CHUNK_SIZE = 25;

    public function __construct(
        private readonly ImportProgressService $progressService,
    ) {}

    /**
     * Complete a test-mode import locally, or dispatch production DataCite
     * updates in bounded parallel queue jobs.
     *
     * @param  list<int>  $resourceIds
     */
    public function dispatch(string $type, string $importId, array $resourceIds, bool $retry = false): void
    {
        $resourceIds = array_values(array_unique(array_map('intval', $resourceIds)));

        if ($resourceIds !== [] && ! $retry) {
            app(PortalKeywordCacheInvalidationService::class)->scheduleAfterCommit();
            CacheKey::PORTAL_RESOURCE_TYPE_FACETS->forget();
            CacheKey::PORTAL_DATACENTER_FACETS->forget();
        }

        if (config('datacite.test_mode') !== false) {
            $this->progressService->markSyncSkipped($type, $importId, count($resourceIds));

            return;
        }

        if ($resourceIds === []) {
            $this->progressService->markCompletedWithoutSync($type, $importId);

            return;
        }

        $this->progressService->beginSync($type, $importId, $resourceIds, $retry);

        $jobs = array_map(
            static fn (array $ids): SyncImportedResourcesWithDataCiteJob => new SyncImportedResourcesWithDataCiteJob(
                $type,
                $importId,
                $ids,
            ),
            array_chunk($resourceIds, self::CHUNK_SIZE),
        );

        Bus::batch($jobs)
            ->name("DataCite import sync {$type} {$importId}")
            ->allowFailures()
            ->onQueue('imports')
            ->finally(static function (Batch $batch) use ($type, $importId): void {
                app(ImportProgressService::class)->finalizeSync($type, $importId);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($type, $importId): void {
                Log::error('Import DataCite sync batch failed', [
                    'import_type' => $type,
                    'import_id' => $importId,
                    'batch_id' => $batch->id,
                    'error' => $exception->getMessage(),
                ]);
            })
            ->dispatch();
    }

    public function retryFailures(string $type, string $importId): bool
    {
        if (config('datacite.test_mode') !== false) {
            return false;
        }

        $resourceIds = $this->progressService->failedResourceIds($type, $importId);

        if ($resourceIds === []) {
            return false;
        }

        $this->dispatch($type, $importId, $resourceIds, true);

        return true;
    }
}
