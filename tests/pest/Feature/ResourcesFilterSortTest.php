<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Test: Resources Filter and Sort Investigation
 * 
 * This test systematically tests all filter and sort options on the /resources route
 * to identify which combinations cause 500 errors.
 * 
 * Bug Report: Some filter and sort options on /resources return 500 errors.
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
                $response = actingAs($this->user)
                    ->get("/resources?sort_key={$sortKey}&sort_direction={$direction}");

                expect($response->status())->toBeLessThan(500);
            });
        }
    }
});

describe('Resources Filter Options', function () {
    it('can filter by status curation', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=curation');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by status review', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=review');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by status published', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=published');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by multiple statuses', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=curation&status[]=review');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by year range', function () {
        $response = actingAs($this->user)
            ->get('/resources?year_from=2020&year_to=2024');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by search term', function () {
        $response = actingAs($this->user)
            ->get('/resources?search=test');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by created date range', function () {
        $response = actingAs($this->user)
            ->get('/resources?created_from=2023-01-01&created_to=2024-12-31');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by updated date range', function () {
        $response = actingAs($this->user)
            ->get('/resources?updated_from=2023-01-01&updated_to=2024-12-31');

        expect($response->status())->toBeLessThan(500);
    });
});

describe('Resources Combined Filter and Sort', function () {
    it('can filter by status and sort by title', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=curation&sort_key=title&sort_direction=asc');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by status and sort by first_author', function () {
        $response = actingAs($this->user)
            ->get('/resources?status[]=review&sort_key=first_author&sort_direction=asc');

        expect($response->status())->toBeLessThan(500);
    });

    it('can filter by year range and sort by curator', function () {
        $response = actingAs($this->user)
            ->get('/resources?year_from=2020&year_to=2024&sort_key=curator&sort_direction=desc');

        expect($response->status())->toBeLessThan(500);
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
            $response = actingAs($this->user)
                ->get("/api/resources/load-more?page=1&sort_key={$sortKey}&sort_direction=asc");

            expect($response->status())->toBeLessThan(500, "API loadMore failed for sort_key={$sortKey}");
        }
    });

    it('can get filter options', function () {
        $response = actingAs($this->user)
            ->get('/api/resources/filter-options');

        expect($response->status())->toBeLessThan(500);
    });
});
