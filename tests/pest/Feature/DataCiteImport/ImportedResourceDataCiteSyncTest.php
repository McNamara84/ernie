<?php

declare(strict_types=1);

use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\ImportedResourceDataCiteSyncDispatcher;
use App\Services\ImportProgressService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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
    $syncService->shouldNotReceive('syncIfRegistered');

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
    $syncService->shouldReceive('syncIfRegistered')
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

it('keeps local data and exposes retryable synchronization failures', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = Resource::factory()->create(['doi' => '10.5880/failure']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $progress = app(ImportProgressService::class);
    $progress->beginSync(ImportProgressService::TYPE_RESOURCE, $this->importId, [$resource->id]);

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')
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

it('finishes locally without dispatching DataCite jobs in test mode', function (): void {
    Config::set('datacite.test_mode', true);
    Bus::fake();

    app(ImportedResourceDataCiteSyncDispatcher::class)->dispatch(
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
