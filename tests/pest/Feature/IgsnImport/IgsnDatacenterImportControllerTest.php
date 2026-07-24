<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\User;
use App\Services\LegacyIgsnPortalService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->portal = Mockery::mock(LegacyIgsnPortalService::class);
    $this->app->instance(LegacyIgsnPortalService::class, $this->portal);
});

afterEach(function (): void {
    Mockery::close();
});

it('lists canonical legacy IGSN datacenters for authorized users', function (): void {
    $datacenter = [
        'id' => 'IGSNDB.GFZ',
        'name' => 'GFZ German Research Centre for Geosciences',
        'legacy_name' => 'GFZ Potsdam',
        'resource_count' => 42,
    ];
    $this->portal->shouldReceive('listDatacenters')->once()->andReturn([$datacenter]);

    $this->actingAs($this->admin)
        ->getJson('/igsns/import/datacenters')
        ->assertOk()
        ->assertExactJson(['datacenters' => [$datacenter]]);
});

it('starts a datacenter import with its legacy identifier and progress context', function (): void {
    Queue::fake();
    $datacenter = [
        'id' => 'IGSNDB.ICDP',
        'name' => 'ICDP',
        'legacy_name' => 'ICDP',
        'resource_count' => 123,
    ];
    $this->portal->shouldReceive('findDatacenter')
        ->once()
        ->with('IGSNDB.ICDP')
        ->andReturn($datacenter);

    $response = $this->actingAs($this->admin)
        ->postJson('/igsns/import/start-datacenter', [
            'datacenter_id' => '  IGSNDB.ICDP  ',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Datacenter IGSN import started successfully');

    $importId = $response->json('import_id');
    $progress = cache()->get("igsn_import:{$importId}");

    expect($progress['total'])->toBe(123)
        ->and($progress['datacenter'])->toBe($datacenter)
        ->and($progress['unassigned'])->toBe(0)
        ->and($progress['warnings'])->toBe([]);

    Queue::assertPushed(
        ImportIgsnsFromDataCiteJob::class,
        fn (ImportIgsnsFromDataCiteJob $job): bool => $job->getLegacyDatacenterId() === 'IGSNDB.ICDP'
            && $job->getSingleDoi() === null,
    );
});

it('rejects invalid and unavailable legacy datacenter selections', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)
        ->postJson('/igsns/import/start-datacenter', ['datacenter_id' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datacenter_id');

    $this->portal->shouldReceive('findDatacenter')
        ->once()
        ->with('IGSNDB.UNKNOWN')
        ->andReturnNull();

    $this->actingAs($this->admin)
        ->postJson('/igsns/import/start-datacenter', ['datacenter_id' => 'IGSNDB.UNKNOWN'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datacenter_id');

    Queue::assertNothingPushed();
});

it('returns a stable service error when the legacy portal is unavailable', function (): void {
    $this->portal->shouldReceive('listDatacenters')
        ->once()
        ->andThrow(new RuntimeException('sensitive upstream detail'));

    $this->actingAs($this->admin)
        ->getJson('/igsns/import/datacenters')
        ->assertServiceUnavailable()
        ->assertJsonPath(
            'message',
            'The legacy IGSN portal is currently unavailable. Please try again later.',
        )
        ->assertJsonMissing(['message' => 'sensitive upstream detail']);
});

it('does not dispatch a datacenter import when portal verification fails', function (): void {
    Queue::fake();
    $this->portal->shouldReceive('findDatacenter')
        ->once()
        ->with('IGSNDB.ICDP')
        ->andThrow(new RuntimeException('sensitive upstream detail'));

    $this->actingAs($this->admin)
        ->postJson('/igsns/import/start-datacenter', [
            'datacenter_id' => 'IGSNDB.ICDP',
        ])
        ->assertServiceUnavailable()
        ->assertJsonPath(
            'message',
            'The legacy IGSN portal is currently unavailable. Please try again later.',
        );

    Queue::assertNothingPushed();
});

it('protects both datacenter import endpoints with the DataCite import policy', function (): void {
    Queue::fake();

    $this->actingAs($this->curator)
        ->getJson('/igsns/import/datacenters')
        ->assertForbidden();

    $this->actingAs($this->curator)
        ->postJson('/igsns/import/start-datacenter', [
            'datacenter_id' => 'IGSNDB.ICDP',
        ])
        ->assertForbidden();

    Queue::assertNothingPushed();
});
