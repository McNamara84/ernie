<?php

declare(strict_types=1);

use App\Http\Requests\Resource\IndexResourcesRequest;
use App\Http\Requests\Resource\LoadMoreResourcesRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::get('/_test/index-resources', function (IndexResourcesRequest $request) {
        return response()->json($request->toCriteria());
    });

    Route::get('/_test/load-more-resources', function (LoadMoreResourcesRequest $request) {
        return response()->json($request->toCriteria());
    });
});

it('rejects unauthenticated users', function (): void {
    $this->getJson('/_test/index-resources')
        ->assertStatus(403);
});

it('returns sane defaults for an empty query string', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources')
        ->assertOk()
        ->assertJson([
            'page' => 1,
            'perPage' => 50,
            'sortKey' => 'updated_at',
            'sortDirection' => 'desc',
            'filters' => [],
        ]);
});

it('clamps per_page to the configured maximum', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?per_page=999')
        ->assertStatus(422);

    $this->actingAs($user)
        ->getJson('/_test/index-resources?per_page=100')
        ->assertOk()
        ->assertJsonPath('perPage', 100);
});

it('rejects invalid sort keys', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?sort_key=banana')
        ->assertStatus(422);
});

it('normalises sort keys to lowercase', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?sort_key=updated_at&sort_direction=asc')
        ->assertOk()
        ->assertJson(['sortKey' => 'updated_at', 'sortDirection' => 'asc']);
});

it('extracts multi-value resource_type filter from arrays', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?resource_type[]=dataset&resource_type[]=software')
        ->assertOk()
        ->assertJsonPath('filters.resource_type', ['dataset', 'software']);
});

it('wraps a single resource_type string into an array filter', function (): void {
    $user = User::factory()->create();

    // Use slug values (e.g. 'dataset') to mirror the real frontend, which
    // sends resource_types.slug as the filter value. ResourceQueryBuilder::
    // applyFilters() filters by resource_types.slug, not name.
    $this->actingAs($user)
        ->getJson('/_test/index-resources?resource_type=dataset')
        ->assertOk()
        ->assertJsonPath('filters.resource_type', ['dataset']);
});

it('passes status values straight through (controller validates them downstream)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?status[]=draft&status[]=published')
        ->assertOk()
        ->assertJsonPath('filters.status', ['draft', 'published']);
});

it('rejects unknown status values regardless of array vs scalar form (Issue: PR #679 review)', function (): void {
    $user = User::factory()->create();

    // Array form
    $this->actingAs($user)
        ->getJson('/_test/index-resources?status[]=bogus')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status.0']);

    // Scalar form must be normalised to array first, then rejected by Rule::in
    $this->actingAs($user)
        ->getJson('/_test/index-resources?status=bogus')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status.0']);
});

it('accepts a single status string and normalises it to an array filter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?status=draft')
        ->assertOk()
        ->assertJsonPath('filters.status', ['draft']);
});

it('extracts year_from / year_to as integers and trims search', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?year_from=2020&year_to=2024&search=%20climate%20')
        ->assertOk()
        ->assertJsonPath('filters.year_from', 2020)
        ->assertJsonPath('filters.year_to', 2024)
        ->assertJsonPath('filters.search', 'climate');
});

it('drops empty filter values silently', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?search=&resource_type=&curator[]=')
        ->assertOk()
        ->assertJson(['filters' => []]);
});

it('rejects invalid date filters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/index-resources?created_from=not-a-date')
        ->assertStatus(422);
});

it('shares the same criteria contract for LoadMoreResourcesRequest', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/load-more-resources?page=3&per_page=25&sort_key=title&sort_direction=asc')
        ->assertOk()
        ->assertJson([
            'page' => 3,
            'perPage' => 25,
            'sortKey' => 'title',
            'sortDirection' => 'asc',
        ]);
});
