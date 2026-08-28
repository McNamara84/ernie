<?php

declare(strict_types=1);

use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->importId = Str::uuid()->toString();
    Cache::put("datacite_import:{$this->importId}", [
        'status' => 'completed',
        'imported' => 1,
    ]);
});

it('has a hard test-mode guard in the queued sync job', function (): void {
    Config::set('datacite.test_mode', true);
    $resource = Resource::factory()->create(['doi' => '10.5880/test-mode']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldNotReceive('syncLandingPageUrlIfRegistered');

    (new SyncImportedResourcesWithDataCiteJob(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
    ))->handle($syncService, app(ImportProgressService::class));
});

it('records successful synchronization separately from import counts', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = Resource::factory()->create(['doi' => '10.5880/success']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(ImportProgressService::TYPE_RESOURCE, $this->importId, [$resource->id]);

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncLandingPageUrlIfRegistered')
        ->once()
        ->andReturn(DataCiteSyncResult::succeeded('10.5880/success'));

    (new SyncImportedResourcesWithDataCiteJob(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
    ))->handle($syncService, $progress);

    expect($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toMatchArray([
            'status' => 'completed',
            'phase' => 'completed',
            'imported' => 1,
            'sync_processed' => 1,
            'sync_succeeded' => 1,
            'sync_failed' => 0,
        ]);
});

it('uses full metadata synchronization for explicitly marked imported resources', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = Resource::factory()->create(['doi' => '10.5880/full-metadata']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
        fullMetadataResourceIds: [$resource->id],
    );

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncLandingPageUrlIfRegistered')->never();
    $syncService->shouldReceive('syncIfRegistered')
        ->once()
        ->withArgs(fn (Resource $candidate): bool => $candidate->is($resource))
        ->andReturn(DataCiteSyncResult::succeeded('10.5880/full-metadata'));

    (new SyncImportedResourcesWithDataCiteJob(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
        [$resource->id],
    ))->handle($syncService, $progress);

    expect($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toMatchArray([
            'sync_processed' => 1,
            'sync_succeeded' => 1,
            'sync_full_metadata_total' => 1,
        ])->not->toHaveKey('sync_full_metadata_resource_ids')
        ->and($progress->fullMetadataResourceIds(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toBe([$resource->id]);
});

it('keeps local data and exposes retryable synchronization failures', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = Resource::factory()->create(['doi' => '10.5880/failure']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(ImportProgressService::TYPE_RESOURCE, $this->importId, [$resource->id]);

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncLandingPageUrlIfRegistered')
        ->once()
        ->andReturn(DataCiteSyncResult::failed('10.5880/failure', 'DataCite unavailable'));

    (new SyncImportedResourcesWithDataCiteJob(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
    ))->handle($syncService, $progress);

    $state = $progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId);
    expect($state)->toMatchArray([
        'status' => 'completed',
        'sync_failed' => 1,
        'sync_retry_available' => true,
    ])->and($state['sync_errors'][0])->toMatchArray([
        'resource_id' => $resource->id,
        'doi' => '10.5880/failure',
        'error' => 'DataCite unavailable',
    ])->and($progress->failedResourceIds(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toBe([$resource->id])
        ->and(Resource::find($resource->id))->not->toBeNull();
});

it('preserves full metadata synchronization when retrying failures', function (): void {
    Config::set('datacite.test_mode', false);
    Bus::fake();
    $resource = Resource::factory()->create(['doi' => '10.5880/full-metadata-retry']);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [$resource->id],
        fullMetadataResourceIds: [$resource->id],
    );
    $progress->recordSyncFailure(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        $resource->id,
        $resource->doi,
        'Temporary failure',
    );

    $retried = app(ImportedResourceDataCiteSyncDispatcherService::class)
        ->retryFailures(ImportProgressService::TYPE_RESOURCE, $this->importId);

    Bus::assertBatched(function (PendingBatch $batch) use ($resource): bool {
        $job = $batch->jobs->first();

        if (! $job instanceof SyncImportedResourcesWithDataCiteJob) {
            return false;
        }

        $fullMetadataResourceIds = new ReflectionProperty($job, 'fullMetadataResourceIds');

        return $fullMetadataResourceIds->getValue($job) === [$resource->id];
    });
    expect($retried)->toBeTrue()
        ->and($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))->toMatchArray([
            'status' => 'running',
            'sync_retry' => true,
            'sync_full_metadata_total' => 1,
        ])->not->toHaveKey('sync_full_metadata_resource_ids')
        ->and($progress->fullMetadataResourceIds(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toBe([$resource->id]);
});

it('can read full metadata IDs from legacy progress during a rolling deployment', function (): void {
    Cache::put("datacite_import:{$this->importId}", [
        'status' => 'completed',
        'sync_full_metadata_resource_ids' => [8, 8, 9],
    ]);

    $progress = app(ImportProgressService::class);

    expect($progress->fullMetadataResourceIds(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
    ))->toBe([8, 9]);

    $progress->beginSync(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [8, 9],
        fullMetadataResourceIds: [9],
    );

    expect($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->not->toHaveKey('sync_full_metadata_resource_ids')
        ->and($progress->fullMetadataResourceIds(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toBe([9]);
});

it('returns no full metadata IDs when progress is missing', function (): void {
    $missingImportId = Str::uuid()->toString();

    expect(app(ImportProgressService::class)->fullMetadataResourceIds(
        ImportProgressService::TYPE_RESOURCE,
        $missingImportId,
    ))->toBe([]);
});

it('replaces malformed cached progress during updates', function (): void {
    Cache::put("datacite_import:{$this->importId}", 'malformed');

    app(ImportProgressService::class)->update(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        ['processed' => 1],
    );

    expect(Cache::get("datacite_import:{$this->importId}"))->toBe(['processed' => 1]);
});

it('replaces malformed cached progress when recording sync results', function (): void {
    Cache::put("datacite_import:{$this->importId}", 42);

    app(ImportProgressService::class)->recordSyncSuccess(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        123,
    );

    expect(Cache::get("datacite_import:{$this->importId}"))->toMatchArray([
        'status' => 'completed',
        'phase' => 'completed',
        'sync_processed' => 1,
        'sync_succeeded' => 1,
    ]);
});

it('finishes locally without dispatching DataCite jobs in test mode', function (): void {
    Config::set('datacite.test_mode', true);
    Bus::fake();

    app(ImportedResourceDataCiteSyncDispatcherService::class)->dispatch(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        [123],
    );

    Bus::assertNothingBatched();
    expect(Cache::get("datacite_import:{$this->importId}"))->toMatchArray([
        'status' => 'completed',
        'phase' => 'completed',
        'sync_total' => 1,
        'sync_processed' => 0,
        'sync_skipped_test_mode' => true,
    ]);
});

it('can initialize synchronization when public progress is missing', function (): void {
    Config::set('datacite.test_mode', false);
    Bus::fake();
    $missingImportId = Str::uuid()->toString();

    app(ImportedResourceDataCiteSyncDispatcherService::class)->dispatch(
        ImportProgressService::TYPE_RESOURCE,
        $missingImportId,
        [123],
    );

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    expect(app(ImportProgressService::class)->get(
        ImportProgressService::TYPE_RESOURCE,
        $missingImportId,
    ))->toMatchArray([
        'status' => 'running',
        'sync_total' => 1,
    ]);
});

it('turns unprocessed batch items into retryable failures during finalization', function (): void {
    Config::set('datacite.test_mode', false);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(ImportProgressService::TYPE_RESOURCE, $this->importId, [41, 42]);

    $progress->finalizeSync(ImportProgressService::TYPE_RESOURCE, $this->importId);

    expect($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))->toMatchArray([
        'status' => 'completed',
        'sync_processed' => 2,
        'sync_failed' => 2,
        'sync_retry_available' => true,
    ])->and($progress->failedResourceIds(ImportProgressService::TYPE_RESOURCE, $this->importId))
        ->toBe([41, 42]);
});

it('does not expose synchronization retries when test mode becomes active before completion', function (): void {
    Config::set('datacite.test_mode', false);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(ImportProgressService::TYPE_RESOURCE, $this->importId, [41]);

    Config::set('datacite.test_mode', true);
    $progress->recordSyncFailure(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        41,
        '10.5880/test-mode-failure',
        'DataCite unavailable',
    );

    expect($progress->get(ImportProgressService::TYPE_RESOURCE, $this->importId))->toMatchArray([
        'status' => 'completed',
        'sync_failed' => 1,
        'sync_retry_available' => false,
    ]);
});

it('retries a timed-out progress lock before applying the update', function (): void {
    $timedOutLock = Mockery::mock(LockContract::class);
    $acquiredLock = Mockery::mock(LockContract::class);
    $progressKey = "datacite_import:{$this->importId}";

    $timedOutLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);
    $acquiredLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(static fn (int $seconds, Closure $callback): mixed => $callback());

    Cache::shouldReceive('lock')
        ->twice()
        ->with("{$progressKey}:lock", 15)
        ->andReturn($timedOutLock, $acquiredLock);
    Cache::shouldReceive('get')
        ->once()
        ->with($progressKey, [])
        ->andReturn(['status' => 'running']);
    Cache::shouldReceive('put')
        ->once()
        ->withArgs(fn (string $key, array $progress, mixed $ttl): bool => $key === $progressKey
            && $progress['processed'] === 1
            && $ttl instanceof DateTimeInterface)
        ->andReturnTrue();

    app(ImportProgressService::class)->update(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        ['processed' => 1],
    );
});

it('logs and continues when a progress lock repeatedly times out', function (): void {
    $lock = Mockery::mock(LockContract::class);
    $progressKey = "datacite_import:{$this->importId}";

    $lock->shouldReceive('block')
        ->times(3)
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')
        ->times(3)
        ->with("{$progressKey}:lock", 15)
        ->andReturn($lock);
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Import progress update skipped after repeated lock timeouts'
            && $context['import_type'] === ImportProgressService::TYPE_RESOURCE
            && $context['import_id'] === $this->importId
            && $context['operation'] === 'update'
            && $context['lock_key'] === "{$progressKey}:lock"
            && $context['attempts'] === 3);

    expect(fn () => app(ImportProgressService::class)->update(
        ImportProgressService::TYPE_RESOURCE,
        $this->importId,
        ['processed' => 1],
    ))->not->toThrow(LockTimeoutException::class);
});

it('does not dispatch synchronization for a cancelled import', function (string $type, string $progressKey): void {
    Config::set('datacite.test_mode', false);
    Bus::fake();
    Cache::put("{$progressKey}:{$this->importId}", [
        'status' => 'cancelled',
        'phase' => 'completed',
    ]);

    app(ImportedResourceDataCiteSyncDispatcherService::class)->dispatch(
        $type,
        $this->importId,
        [123],
    );

    Bus::assertNothingBatched();
    expect(Cache::get("{$progressKey}:{$this->importId}"))->toBe([
        'status' => 'cancelled',
        'phase' => 'completed',
    ]);
})->with([
    'resource import' => [ImportProgressService::TYPE_RESOURCE, 'datacite_import'],
    'IGSN import' => [ImportProgressService::TYPE_IGSN, 'igsn_import'],
]);
