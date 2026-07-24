<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\Datacenter;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\IgsnChildDiscoveryService;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnImportService;
use App\Services\LegacyIgsnPortalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->importService = Mockery::mock(IgsnImportService::class);
    $this->transformer = Mockery::mock(DataCiteToIgsnTransformer::class);
    $this->enrichment = Mockery::mock(IgsnEnrichmentService::class);
    $this->portal = Mockery::mock(LegacyIgsnPortalService::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('assigns matched imports, reports unmatched imports, and never changes existing resources', function (): void {
    $oldDatacenter = Datacenter::factory()->create();
    $existing = Resource::factory()->create([
        'doi' => '10.60510/existing001',
        'datacenter_id' => $oldDatacenter->id,
    ]);

    $this->portal->shouldReceive('assignmentsForAllIgsns')->once()->andReturn([
        '10.60510/gfnew001' => Datacenter::GFZ_NAME,
        '10.60510/existing001' => 'ICDP',
    ]);
    $this->importService->shouldReceive('getTotalIgsnCount')->once()->andReturn(3);
    $this->importService->shouldReceive('fetchAllIgsns')->once()->andReturn((function () {
        yield igsnDatacenterRecord('GFNEW001');
        yield igsnDatacenterRecord('UNMATCHED001');
        yield igsnDatacenterRecord('EXISTING001');
    })());
    $this->transformer->shouldReceive('transform')
        ->twice()
        ->andReturnUsing(fn (array $record): Resource => createDatacenterJobResource($record));
    $this->enrichment->shouldReceive('enrich')->twice()->andReturnFalse();

    $importId = Str::uuid()->toString();
    (new ImportIgsnsFromDataCiteJob($this->user->id, $importId))->handle(
        $this->importService,
        $this->transformer,
        $this->enrichment,
        null,
        $this->portal,
    );

    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    expect(Resource::query()->where('doi', '10.60510/gfnew001')->value('datacenter_id'))->toBe($gfz->id)
        ->and(Resource::query()->where('doi', '10.60510/unmatched001')->value('datacenter_id'))->toBeNull()
        ->and($existing->fresh()->datacenter_id)->toBe($oldDatacenter->id);

    $progress = Cache::get("igsn_import:{$importId}");
    expect($progress['imported'])->toBe(2)
        ->and($progress['skipped'])->toBe(1)
        ->and($progress['unassigned'])->toBe(1)
        ->and($progress['unassigned_dois'])->toBe(['10.60510/unmatched001'])
        ->and($progress['warnings'][0])->toContain('1 newly imported IGSN');
});

it('aborts before the first database write when the legacy portal fails', function (): void {
    $resourceCount = Resource::query()->count();
    $datacenterCount = Datacenter::query()->count();

    $this->portal->shouldReceive('assignmentsForAllIgsns')
        ->once()
        ->andThrow(new RuntimeException('portal unavailable'));
    $this->importService->shouldReceive('getTotalIgsnCount')->never();
    $this->transformer->shouldReceive('transform')->never();

    $job = new ImportIgsnsFromDataCiteJob($this->user->id, Str::uuid()->toString());

    expect(fn () => $job->handle(
        $this->importService,
        $this->transformer,
        $this->enrichment,
        null,
        $this->portal,
    ))->toThrow(RuntimeException::class, 'portal unavailable');

    expect(Resource::query()->count())->toBe($resourceCount)
        ->and(Datacenter::query()->count())->toBe($datacenterCount);
});

it('imports a selected datacenter efficiently and assigns only newly created resources', function (): void {
    $oldDatacenter = Datacenter::factory()->create();
    Resource::factory()->create([
        'doi' => '10.60510/icdpexisting',
        'datacenter_id' => $oldDatacenter->id,
    ]);

    $selection = [
        'datacenter' => [
            'id' => 'IGSNDB.ICDP',
            'name' => 'ICDP',
            'legacy_name' => 'ICDP',
            'resource_count' => 3,
        ],
        'dois' => [
            '10.60510/icdpall',
            '10.60510/icdpexisting',
            '10.60510/icdpsingle',
        ],
    ];
    $this->portal->shouldReceive('igsnsForDatacenter')
        ->once()
        ->with('IGSNDB.ICDP')
        ->andReturn($selection);
    $this->importService->shouldReceive('fetchAllIgsns')->once()->andReturn((function () {
        yield igsnDatacenterRecord('UNRELATED');
        yield igsnDatacenterRecord('ICDPALL');
        yield igsnDatacenterRecord('ICDPEXISTING');
    })());
    $this->importService->shouldReceive('fetchSingleIgsn')
        ->once()
        ->with('10.60510/icdpsingle')
        ->andReturn(igsnDatacenterRecord('ICDPSINGLE'));
    $this->transformer->shouldReceive('transform')
        ->twice()
        ->andReturnUsing(fn (array $record): Resource => createDatacenterJobResource($record));
    $this->enrichment->shouldReceive('enrich')->twice()->andReturnFalse();

    $importId = Str::uuid()->toString();
    $job = new ImportIgsnsFromDataCiteJob(
        $this->user->id,
        $importId,
        null,
        'IGSNDB.ICDP',
    );
    $job->handle(
        $this->importService,
        $this->transformer,
        $this->enrichment,
        null,
        $this->portal,
    );

    $icdp = Datacenter::query()->where('name', 'ICDP')->firstOrFail();
    expect(Resource::query()->where('doi', '10.60510/icdpall')->value('datacenter_id'))->toBe($icdp->id)
        ->and(Resource::query()->where('doi', '10.60510/icdpsingle')->value('datacenter_id'))->toBe($icdp->id)
        ->and(Resource::query()->where('doi', '10.60510/icdpexisting')->value('datacenter_id'))->toBe($oldDatacenter->id);

    $progress = Cache::get("igsn_import:{$importId}");
    expect($progress['status'])->toBe('completed')
        ->and($progress['total'])->toBe(3)
        ->and($progress['imported'])->toBe(2)
        ->and($progress['skipped'])->toBe(1)
        ->and($progress['datacenter']['id'])->toBe('IGSNDB.ICDP')
        ->and($progress['unassigned'])->toBe(0);
});

it('preserves cancellation and avoids portal access before a datacenter import starts', function (): void {
    $importId = Str::uuid()->toString();
    $datacenter = [
        'id' => 'IGSNDB.ICDP',
        'name' => 'ICDP',
        'legacy_name' => 'ICDP',
        'resource_count' => 123,
    ];

    Cache::put("igsn_import:{$importId}", [
        'status' => 'cancelled',
        'total' => 123,
        'processed' => 0,
        'imported' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enriched' => 0,
        'datacenter' => $datacenter,
        'unassigned' => 0,
        'unassigned_dois' => [],
        'warnings' => [],
    ]);

    $this->portal->shouldReceive('igsnsForDatacenter')->never();
    $this->importService->shouldReceive('fetchAllIgsns')->never();
    $this->transformer->shouldReceive('transform')->never();

    $job = new ImportIgsnsFromDataCiteJob(
        $this->user->id,
        $importId,
        null,
        'IGSNDB.ICDP',
    );
    $job->handle(
        $this->importService,
        $this->transformer,
        $this->enrichment,
        null,
        $this->portal,
    );

    $progress = Cache::get("igsn_import:{$importId}");
    expect($progress['status'])->toBe('cancelled')
        ->and($progress['total'])->toBe(123)
        ->and($progress['datacenter'])->toBe($datacenter)
        ->and($progress['completed_at'])->not->toBeNull();
});

it('assigns the legacy datacenter during a single IGSN import', function (): void {
    $record = igsnDatacenterRecord('GFSINGLE');
    $childDiscovery = Mockery::mock(IgsnChildDiscoveryService::class);

    $this->importService->shouldReceive('fetchSingleIgsn')
        ->once()
        ->with('10.60510/gfsingle')
        ->andReturn($record);
    $this->importService->shouldReceive('extractParentDois')
        ->once()
        ->with($record)
        ->andReturn([]);
    $this->importService->shouldReceive('fetchChildIgsnsForParent')
        ->once()
        ->with('10.60510/gfsingle')
        ->andReturn([]);
    $childDiscovery->shouldReceive('discoverDirectChildHandles')
        ->once()
        ->with('GFSINGLE')
        ->andReturn([]);
    $this->portal->shouldReceive('assignmentsForHandles')
        ->once()
        ->with(['GFSINGLE'])
        ->andReturn(['10.60510/gfsingle' => Datacenter::GFZ_NAME]);
    $this->transformer->shouldReceive('transform')
        ->once()
        ->andReturnUsing(fn (array $data): Resource => createDatacenterJobResource($data));
    $this->enrichment->shouldReceive('enrich')->once()->andReturnFalse();

    $importId = Str::uuid()->toString();
    $job = new ImportIgsnsFromDataCiteJob(
        $this->user->id,
        $importId,
        '10.60510/gfsingle',
    );
    $job->handle(
        $this->importService,
        $this->transformer,
        $this->enrichment,
        $childDiscovery,
        $this->portal,
    );

    $gfz = Datacenter::query()->where('name', Datacenter::GFZ_NAME)->firstOrFail();
    expect(Resource::query()->where('doi', '10.60510/gfsingle')->value('datacenter_id'))->toBe($gfz->id);

    $progress = Cache::get("igsn_import:{$importId}");
    expect($progress['unassigned'])->toBe(0)
        ->and($progress['warnings'])->toBe([]);
});

it('rejects mutually exclusive single and datacenter modes', function (): void {
    expect(fn () => new ImportIgsnsFromDataCiteJob(
        $this->user->id,
        Str::uuid()->toString(),
        '10.60510/sample',
        'IGSNDB.ICDP',
    ))->toThrow(InvalidArgumentException::class, 'mutually exclusive');
});

/**
 * @return array<string, mixed>
 */
function igsnDatacenterRecord(string $handle): array
{
    return [
        'id' => '10.60510/'.strtolower($handle),
        'attributes' => [
            'doi' => '10.60510/'.strtolower($handle),
            'titles' => [['title' => $handle]],
            'publicationYear' => 2024,
            'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $record
 */
function createDatacenterJobResource(array $record): Resource
{
    $resource = Resource::factory()->create([
        'doi' => $record['attributes']['doi'],
        'datacenter_id' => null,
    ]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
    $resource->load('igsnMetadata');

    return $resource;
}
