<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\User;
use App\Services\GfzDataServicesPortalService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Bus::fake();

    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

afterEach(function () {
    Mockery::close();
});

function mockPortalDatacenters(array $datacenters): GfzDataServicesPortalService
{
    $portalService = Mockery::mock(GfzDataServicesPortalService::class);
    $portalService
        ->shouldReceive('listDatacenters')
        ->zeroOrMoreTimes()
        ->andReturn($datacenters);

    app()->instance(GfzDataServicesPortalService::class, $portalService);

    return $portalService;
}

describe('datacenter import endpoints', function () {
    it('returns the portal datacenters to authorized users', function () {
        mockPortalDatacenters([
            ['id' => 'GFZ', 'name' => 'GFZ Data Services', 'resource_count' => 1200],
            ['id' => 'ArboDat', 'name' => 'ArboDat 2016', 'resource_count' => 172],
        ]);

        $response = $this->actingAs($this->groupLeader)
            ->getJson('/datacite/import/datacenters');

        $response
            ->assertOk()
            ->assertExactJson([
                'datacenters' => [
                    ['id' => 'GFZ', 'name' => 'GFZ Data Services', 'resource_count' => 1200],
                    ['id' => 'ArboDat', 'name' => 'ArboDat 2016', 'resource_count' => 172],
                ],
            ]);
    });

    it('rejects users without DataCite import permission', function () {
        mockPortalDatacenters([]);

        $this->actingAs($this->curator)
            ->getJson('/datacite/import/datacenters')
            ->assertForbidden();

        $this->actingAs($this->curator)
            ->postJson('/datacite/import/start-datacenter', ['datacenter_id' => 'GFZ'])
            ->assertForbidden();

        $this->actingAs($this->beginner)
            ->getJson('/datacite/import/datacenters')
            ->assertForbidden();

        Bus::assertNothingDispatched();
    });

    it('requires authentication for both datacenter endpoints', function () {
        $this->getJson('/datacite/import/datacenters')->assertUnauthorized();
        $this->postJson('/datacite/import/start-datacenter', ['datacenter_id' => 'GFZ'])
            ->assertUnauthorized();
    });

    it('returns a safe service-unavailable response when the portal list fails', function () {
        $portalService = Mockery::mock(GfzDataServicesPortalService::class);
        $portalService
            ->shouldReceive('listDatacenters')
            ->once()
            ->andThrow(new RuntimeException('Internal upstream details'));
        app()->instance(GfzDataServicesPortalService::class, $portalService);

        $this->actingAs($this->admin)
            ->getJson('/datacite/import/datacenters')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'The GFZ Data Services datacenter list is currently unavailable. Please try again later.',
            ])
            ->assertDontSee('Internal upstream details');
    });

    it('validates that a datacenter was selected', function () {
        mockPortalDatacenters([]);

        $this->actingAs($this->admin)
            ->postJson('/datacite/import/start-datacenter')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('datacenter_id');

        Bus::assertNothingDispatched();
    });

    it('rejects a datacenter that disappeared from the current portal facets', function () {
        $portalService = mockPortalDatacenters([]);
        $portalService
            ->shouldReceive('findDatacenter')
            ->once()
            ->with('retired')
            ->andReturnNull();

        $this->actingAs($this->admin)
            ->postJson('/datacite/import/start-datacenter', ['datacenter_id' => 'retired'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('datacenter_id');

        Bus::assertNothingDispatched();
    });

    it('starts a datacenter job and initializes progress', function () {
        $portalService = mockPortalDatacenters([]);
        $portalService
            ->shouldReceive('findDatacenter')
            ->once()
            ->with('ArboDat')
            ->andReturn([
                'id' => 'ArboDat',
                'name' => 'ArboDat 2016',
                'resource_count' => 172,
            ]);

        $response = $this->actingAs($this->groupLeader)
            ->postJson('/datacite/import/start-datacenter', ['datacenter_id' => ' ArboDat ']);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Datacenter import started successfully',
            ]);

        $importId = $response->json('import_id');

        expect($importId)->toBeString()
            ->and(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'pending',
                'total' => 0,
                'processed' => 0,
            ]);

        Bus::assertDispatched(
            ImportFromDataCiteJob::class,
            fn (ImportFromDataCiteJob $job): bool => $job->getDatacenterId() === 'ArboDat'
                && $job->getSingleDoi() === null,
        );
    });

    it('does not dispatch when current datacenter validation is unavailable', function () {
        $portalService = mockPortalDatacenters([]);
        $portalService
            ->shouldReceive('findDatacenter')
            ->once()
            ->andThrow(new RuntimeException('portal timeout'));

        $this->actingAs($this->admin)
            ->postJson('/datacite/import/start-datacenter', ['datacenter_id' => 'GFZ'])
            ->assertStatus(503);

        Bus::assertNothingDispatched();
    });
});
