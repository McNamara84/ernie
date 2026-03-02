<?php

declare(strict_types=1);

/**
 * IGSN Filter Feature Tests
 *
 * Tests for prefix and status filter functionality on the IGSN list page (/igsns).
 * Prefix filters on the DOI part before the slash, status filters on upload_status.
 */

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
): Resource {
    $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::create([
        'doi' => $igsn,
        'publication_year' => '2025',
        'version' => '1.0',
        'resource_type_id' => $physicalObjectType->id,
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
    });

    it('returns empty arrays when no IGSNs exist', function () {
        $response = $this->actingAs($this->user)->get('/igsns/filter-options');

        $response->assertStatus(200);
        $response->assertJson([
            'prefixes' => [],
            'statuses' => [],
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
