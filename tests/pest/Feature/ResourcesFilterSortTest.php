<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Test: Resources Filter and Sort Validation
 *
 * This test systematically tests all filter and sort options on the /resources route
 * to ensure all combinations work correctly.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Resources Sort Options', function () {
    $sortKeys = [
        'id',
        'doi',
        'title',
        'resourcetypegeneral',
        'first_author',
        'year',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ];

    $sortDirections = ['asc', 'desc'];

    foreach ($sortKeys as $sortKey) {
        foreach ($sortDirections as $direction) {
            it("can sort by {$sortKey} {$direction}", function () use ($sortKey, $direction) {
                actingAs($this->user)
                    ->get("/resources?sort_key={$sortKey}&sort_direction={$direction}")
                    ->assertOk();
            });
        }
    }
});

describe('Resources Filter Options', function () {
    it('can filter by resource type', function () {
        actingAs($this->user)
            ->get('/resources?resource_type[]=dataset')
            ->assertOk();
    });

    it('can filter by multiple resource types', function () {
        actingAs($this->user)
            ->get('/resources?resource_type[]=dataset&resource_type[]=software')
            ->assertOk();
    });

    it('can filter by curator', function () {
        actingAs($this->user)
            ->get('/resources?curator[]=Test%20User')
            ->assertOk();
    });

    it('can filter by multiple curators', function () {
        actingAs($this->user)
            ->get('/resources?curator[]=Test%20User&curator[]=Admin')
            ->assertOk();
    });

    it('can filter by status curation', function () {
        actingAs($this->user)
            ->get('/resources?status[]=curation')
            ->assertOk();
    });

    it('can filter by status review', function () {
        actingAs($this->user)
            ->get('/resources?status[]=review')
            ->assertOk();
    });

    it('can filter by status published', function () {
        actingAs($this->user)
            ->get('/resources?status[]=published')
            ->assertOk();
    });

    it('can filter by multiple statuses', function () {
        actingAs($this->user)
            ->get('/resources?status[]=curation&status[]=review')
            ->assertOk();
    });

    it('can filter by year range', function () {
        actingAs($this->user)
            ->get('/resources?year_from=2020&year_to=2024')
            ->assertOk();
    });

    it('can filter by search term', function () {
        actingAs($this->user)
            ->get('/resources?search=test')
            ->assertOk();
    });

    it('can filter by created date range', function () {
        actingAs($this->user)
            ->get('/resources?created_from=2023-01-01&created_to=2024-12-31')
            ->assertOk();
    });

    it('can filter by updated date range', function () {
        actingAs($this->user)
            ->get('/resources?updated_from=2023-01-01&updated_to=2024-12-31')
            ->assertOk();
    });
});

describe('Resources Combined Filter and Sort', function () {
    it('can filter by status and sort by title', function () {
        actingAs($this->user)
            ->get('/resources?status[]=curation&sort_key=title&sort_direction=asc')
            ->assertOk();
    });

    it('can filter by status and sort by first_author', function () {
        actingAs($this->user)
            ->get('/resources?status[]=review&sort_key=first_author&sort_direction=asc')
            ->assertOk();
    });

    it('can filter by year range and sort by curator', function () {
        actingAs($this->user)
            ->get('/resources?year_from=2020&year_to=2024&sort_key=curator&sort_direction=desc')
            ->assertOk();
    });
});

describe('Resources API Endpoints', function () {
    it('can load more resources with all sort keys', function () {
        $sortKeys = [
            'id',
            'doi',
            'title',
            'resourcetypegeneral',
            'first_author',
            'year',
            'curator',
            'publicstatus',
            'created_at',
            'updated_at',
        ];

        foreach ($sortKeys as $sortKey) {
            actingAs($this->user)
                ->get("/resources/load-more?page=1&sort_key={$sortKey}&sort_direction=asc")
                ->assertOk();
        }
    });

    it('can get filter options', function () {
        actingAs($this->user)
            ->get('/resources/filter-options')
            ->assertOk();
    });
});
