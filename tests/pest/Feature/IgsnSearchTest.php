<?php

declare(strict_types=1);

/**
 * IGSN Search Feature Tests
 *
 * Tests for the search functionality on the IGSN list page (/igsns).
 * Search filters on DOI (where IGSN is stored) and title values.
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
 * Helper to create an IGSN resource with a given DOI and title.
 */
function createSearchableIgsn(string $igsn, string $title = 'Untitled'): Resource
{
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
        'sample_type' => 'rock core',
        'material' => 'granite',
        'upload_status' => 'pending',
    ]);

    return $resource;
}

describe('IGSN Search', function () {
    it('returns all IGSNs when no search parameter is provided', function () {
        createSearchableIgsn('IGSN-ALPHA-001', 'Alpha Sample');
        createSearchableIgsn('IGSN-BETA-002', 'Beta Sample');
        createSearchableIgsn('IGSN-GAMMA-003', 'Gamma Sample');

        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 3)
            ->where('search', '')
        );
    });

    it('returns all IGSNs when search parameter is empty', function () {
        createSearchableIgsn('IGSN-ALPHA-001', 'Alpha Sample');
        createSearchableIgsn('IGSN-BETA-002', 'Beta Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
        );
    });

    it('ignores search terms shorter than 3 characters', function () {
        createSearchableIgsn('IGSN-AB-001', 'AB Sample');
        createSearchableIgsn('IGSN-CD-002', 'CD Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=AB');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('search', '')
        );
    });

    it('filters IGSNs by DOI search', function () {
        createSearchableIgsn('IGSN-ALPHA-001', 'Alpha Sample');
        createSearchableIgsn('IGSN-BETA-002', 'Beta Sample');
        createSearchableIgsn('IGSN-GAMMA-003', 'Gamma Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=BETA');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', 'IGSN-BETA-002')
            ->where('search', 'BETA')
        );
    });

    it('filters IGSNs by title search', function () {
        createSearchableIgsn('IGSN-001', 'Sediment Core from Lake Constance');
        createSearchableIgsn('IGSN-002', 'Rock Sample from Alps');
        createSearchableIgsn('IGSN-003', 'Soil Sample from Black Forest');

        $response = $this->actingAs($this->user)->get('/igsns?' . http_build_query(['search' => 'Lake Constance']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.title', 'Sediment Core from Lake Constance')
        );
    });

    it('is case-insensitive when searching', function () {
        createSearchableIgsn('IGSN-UPPER-001', 'Granite Core');
        createSearchableIgsn('IGSN-LOWER-002', 'Basalt Sample');

        // Search with lowercase for uppercase DOI
        $response = $this->actingAs($this->user)->get('/igsns?search=upper');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', 'IGSN-UPPER-001')
        );
    });

    it('returns no results when search matches nothing', function () {
        createSearchableIgsn('IGSN-001', 'Some Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=nonexistent');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 0)
        );
    });

    it('preserves sort parameters when searching', function () {
        createSearchableIgsn('IGSN-AAA', 'Zebra Sample');
        createSearchableIgsn('IGSN-ZZZ', 'Alpha Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=Sample&sort=title&direction=asc');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 2)
            ->where('sort.key', 'title')
            ->where('sort.direction', 'asc')
            ->where('search', 'Sample')
            ->where('igsns.0.title', 'Alpha Sample')
            ->where('igsns.1.title', 'Zebra Sample')
        );
    });

    it('matches partial DOI strings', function () {
        createSearchableIgsn('10.12345/IGSN.SAMPLE.001', 'First Sample');
        createSearchableIgsn('10.67890/IGSN.SAMPLE.002', 'Second Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=12345');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', '10.12345/IGSN.SAMPLE.001')
        );
    });

    it('passes search prop back to frontend', function () {
        createSearchableIgsn('IGSN-001', 'Test Sample');

        $response = $this->actingAs($this->user)->get('/igsns?search=Test');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->where('search', 'Test')
        );
    });
});
