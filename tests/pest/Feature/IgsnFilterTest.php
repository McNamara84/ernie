<?php

declare(strict_types=1);

/**
 * IGSN Filter Feature Tests
 *
 * Tests for prefix and status filter functionality on the IGSN list page (/igsns).
 * Prefix filters on the DOI part before the slash, status filters on upload_status.
 */

use App\Models\Datacenter;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'FunderIdentifierTypeSeeder']);

    $this->user = User::factory()->admin()->create();
});

/**
 * Helper to create an IGSN resource with a given DOI, title, and status.
 */
function createFilterableIgsn(
    string $igsn,
    string $title = 'Untitled',
    string $status = 'pending',
    string $sampleType = 'rock core',
    string $material = 'granite',
    ?int $datacenterId = null,
): Resource {
    $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::create([
        'doi' => $igsn,
        'publication_year' => '2025',
        'version' => '1.0',
        'resource_type_id' => $physicalObjectType->id,
        'datacenter_id' => $datacenterId,
    ]);

    $resource->titles()->create([
        'value' => $title,
        'title_type_id' => $mainTitleType->id,
    ]);

    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'sample_type' => $sampleType,
        'material' => $material,
        'upload_status' => $status,
    ]);

    return $resource;
}

// ============================================================================
// Pagination
// ============================================================================

describe('IGSN Pagination', function () {
    it('uses 100 IGSNs per page by default', function () {
        $this->actingAs($this->user)
            ->get('/igsns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->where('pagination.per_page', 100)
                ->where('pagination.current_page', 1)
            );
    });

    it('accepts every selectable page size', function (int $perPage) {
        $this->actingAs($this->user)
            ->get('/igsns?per_page='.$perPage)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->where('pagination.per_page', $perPage)
            );
    })->with([10, 100, 1000]);

    it('normalizes unsupported legacy page sizes to the default', function (int $perPage) {
        $this->actingAs($this->user)
            ->get('/igsns?per_page='.$perPage)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->where('pagination.per_page', 100)
            );
    })->with([25, 50]);

    it('rejects out-of-range page sizes', function (int $perPage) {
        $this->actingAs($this->user)
            ->getJson('/igsns?per_page='.$perPage)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    })->with([0, 1001]);

    it('returns the requested page', function () {
        foreach (range(1, 11) as $index) {
            createFilterableIgsn('10.60516/PAGE'.$index, 'Sample '.$index);
        }

        $this->actingAs($this->user)
            ->get('/igsns?per_page=10&page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->has('igsns', 1)
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.per_page', 10)
                ->where('pagination.total', 11)
            );
    });
});

// ============================================================================
// Filter Options Endpoint
// ============================================================================

describe('IGSN Filter Options', function () {
    it('returns available prefixes and statuses', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');
        createFilterableIgsn('10.58052/SSH001', 'Sample C', 'pending');

        $response = $this->actingAs($this->user)->get('/igsns/filter-options');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'prefixes');
        $response->assertJsonFragment(['prefixes' => ['10.58052', '10.60516']]);
        $response->assertJsonCount(2, 'statuses');
        $response->assertJson(['statuses' => ['pending', 'registered']]);
        $response->assertJson(['datacenters' => []]);
    });

    it('returns empty arrays when no IGSNs exist', function () {
        $response = $this->actingAs($this->user)->get('/igsns/filter-options');

        $response->assertStatus(200);
        $response->assertJson([
            'prefixes' => [],
            'statuses' => [],
            'datacenters' => [],
        ]);
    });

    it('excludes DOIs without a slash from prefixes', function () {
        createFilterableIgsn('IGSN-NO-SLASH', 'No Slash Sample');
        createFilterableIgsn('10.60516/AU1101', 'Valid Sample');

        $response = $this->actingAs($this->user)->get('/igsns/filter-options');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'prefixes');
        $response->assertJsonFragment(['prefixes' => ['10.60516']]);
    });

    it('returns sorted prefixes', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A');
        createFilterableIgsn('10.58052/SSH001', 'Sample B');
        createFilterableIgsn('10.58095/MBCR001', 'Sample C');

        $response = $this->actingAs($this->user)->get('/igsns/filter-options');

        $response->assertStatus(200);
        $prefixes = $response->json('prefixes');
        expect($prefixes)->toBe(['10.58052', '10.58095', '10.60516']);
    });

    it('returns only sorted datacenters assigned to IGSNs', function () {
        $alphaDatacenter = Datacenter::factory()->create(['name' => 'Alpha Samples']);
        $betaDatacenter = Datacenter::factory()->create(['name' => 'Beta Samples']);
        $regularResourceDatacenter = Datacenter::factory()->create(['name' => 'Regular Resources']);
        Datacenter::factory()->create(['name' => 'Unused Datacenter']);

        createFilterableIgsn('10.60516/AU1101', 'Beta Sample', datacenterId: $betaDatacenter->id);
        createFilterableIgsn('10.60516/AU1102', 'Alpha Sample', datacenterId: $alphaDatacenter->id);

        $datasetType = ResourceType::where('slug', 'dataset')->firstOrFail();
        Resource::factory()->create([
            'resource_type_id' => $datasetType->id,
            'datacenter_id' => $regularResourceDatacenter->id,
        ]);

        $this->actingAs($this->user)
            ->get('/igsns/filter-options')
            ->assertOk()
            ->assertJsonPath('datacenters', [
                ['id' => $alphaDatacenter->id, 'name' => 'Alpha Samples'],
                ['id' => $betaDatacenter->id, 'name' => 'Beta Samples'],
            ]);
    });

    it('requires authentication', function () {
        $response = $this->get('/igsns/filter-options');

        $response->assertRedirect('/login');
    });
});

// ============================================================================
// Prefix Filter
// ============================================================================

describe('IGSN Prefix Filter', function () {
    it('filters IGSNs by prefix', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A');
        createFilterableIgsn('10.60516/AU1102', 'Sample B');
        createFilterableIgsn('10.58052/SSH001', 'Sample C');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.60516');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('filters.prefix', '10.60516')
        );
    });

    it('returns all IGSNs when prefix is empty', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A');
        createFilterableIgsn('10.58052/SSH001', 'Sample B');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('filters.prefix', '')
        );
    });

    it('returns no results for non-existent prefix', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.99999');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 0)
        );
    });
});

// ============================================================================
// Status Filter
// ============================================================================

describe('IGSN Status Filter', function () {
    it('filters IGSNs by status', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');
        createFilterableIgsn('10.58052/SSH001', 'Sample C', 'pending');

        $response = $this->actingAs($this->user)->get('/igsns?status=pending');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('filters.status', 'pending')
        );
    });

    it('returns all IGSNs when status is empty', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');

        $response = $this->actingAs($this->user)->get('/igsns?status=');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('filters.status', '')
        );
    });

    it('ignores invalid status values', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');

        $response = $this->actingAs($this->user)->get('/igsns?status=invalid_status');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('filters.status', '')
        );
    });
});

// ============================================================================
// Datacenter Filter
// ============================================================================

describe('IGSN Datacenter Filter', function () {
    it('filters IGSNs by one datacenter', function () {
        $selectedDatacenter = Datacenter::factory()->create();
        $otherDatacenter = Datacenter::factory()->create();

        $selectedIgsn = createFilterableIgsn('10.60516/AU1101', 'Selected Sample', datacenterId: $selectedDatacenter->id);
        createFilterableIgsn('10.60516/AU1102', 'Other Sample', datacenterId: $otherDatacenter->id);
        createFilterableIgsn('10.60516/AU1103', 'Unassigned Sample');

        $this->actingAs($this->user)
            ->get('/igsns?datacenter_id='.$selectedDatacenter->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->has('igsns', 1)
                ->where('igsns.0.id', $selectedIgsn->id)
                ->where('filters.datacenter_id', $selectedDatacenter->id)
                ->missing('filters.without_datacenter')
                ->where('pagination.total', 1)
                ->where('totalCount', 3)
            );
    });

    it('filters IGSNs without a datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        createFilterableIgsn('10.60516/AU1101', 'Assigned Sample', datacenterId: $datacenter->id);
        $unassignedIgsn = createFilterableIgsn('10.60516/AU1102', 'Unassigned Sample');

        $this->actingAs($this->user)
            ->get('/igsns?without_datacenter=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->has('igsns', 1)
                ->where('igsns.0.id', $unassignedIgsn->id)
                ->where('filters.without_datacenter', true)
                ->missing('filters.datacenter_id')
                ->where('pagination.total', 1)
                ->where('totalCount', 2)
            );
    });

    it('combines datacenter, prefix, status, and search filters', function () {
        $selectedDatacenter = Datacenter::factory()->create();
        $otherDatacenter = Datacenter::factory()->create();

        $match = createFilterableIgsn(
            '10.60516/AU1101',
            'Granite Match',
            'pending',
            datacenterId: $selectedDatacenter->id,
        );
        createFilterableIgsn('10.60516/AU1102', 'Basalt', 'pending', datacenterId: $selectedDatacenter->id);
        createFilterableIgsn('10.60516/AU1103', 'Granite Registered', 'registered', datacenterId: $selectedDatacenter->id);
        createFilterableIgsn('10.58052/SSH001', 'Granite Other Prefix', 'pending', datacenterId: $selectedDatacenter->id);
        createFilterableIgsn('10.60516/AU1104', 'Granite Other Datacenter', 'pending', datacenterId: $otherDatacenter->id);

        $this->actingAs($this->user)
            ->get('/igsns?datacenter_id='.$selectedDatacenter->id.'&prefix=10.60516&status=pending&search=Granite')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('igsns/index')
                ->has('igsns', 1)
                ->where('igsns.0.id', $match->id)
                ->where('filters.datacenter_id', $selectedDatacenter->id)
                ->where('filters.prefix', '10.60516')
                ->where('filters.status', 'pending')
                ->where('search', 'Granite')
                ->where('pagination.total', 1)
                ->where('totalCount', 5)
            );
    });

    it('rejects unknown and mutually exclusive datacenter filters', function () {
        $datacenter = Datacenter::factory()->create();

        $this->actingAs($this->user)
            ->getJson('/igsns?datacenter_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['datacenter_id']);

        $this->actingAs($this->user)
            ->getJson('/igsns?datacenter_id='.$datacenter->id.'&without_datacenter=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['datacenter_id']);
    });
});

// ============================================================================
// Combined Filters
// ============================================================================

describe('IGSN Combined Filters', function () {
    it('combines prefix and status filters', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');
        createFilterableIgsn('10.58052/SSH001', 'Sample C', 'pending');
        createFilterableIgsn('10.58052/SSH002', 'Sample D', 'registered');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.60516&status=pending');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', '10.60516/AU1101')
            ->where('filters.prefix', '10.60516')
            ->where('filters.status', 'pending')
        );
    });

    it('combines prefix filter with search', function () {
        createFilterableIgsn('10.60516/AU1101', 'Alpha Sample', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Beta Sample', 'pending');
        createFilterableIgsn('10.58052/SSH001', 'Alpha Sample SSH', 'pending');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.60516&search=Alpha');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', '10.60516/AU1101')
        );
    });

    it('combines all filters together', function () {
        createFilterableIgsn('10.60516/AU1101', 'Alpha Sample', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Alpha Registered', 'registered');
        createFilterableIgsn('10.58052/SSH001', 'Alpha SSH', 'pending');
        createFilterableIgsn('10.60516/AU1103', 'Beta Sample', 'pending');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.60516&status=pending&search=Alpha');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', '10.60516/AU1101')
        );
    });

    it('returns correct totalCount with active filters', function () {
        createFilterableIgsn('10.60516/AU1101', 'Sample A', 'pending');
        createFilterableIgsn('10.60516/AU1102', 'Sample B', 'registered');
        createFilterableIgsn('10.58052/SSH001', 'Sample C', 'pending');

        $response = $this->actingAs($this->user)->get('/igsns?prefix=10.60516');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('totalCount', 3)
            ->where('pagination.total', 2)
        );
    });
});
