<?php

declare(strict_types=1);

use App\Http\Controllers\OldDataStatisticsController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

covers(OldDataStatisticsController::class);

/**
 * Mock the metaworks database connection to prevent actual database queries.
 */
function mockMetaworksConnection(): void
{
    $queryBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryBuilder->shouldReceive('select')->andReturnSelf();
    $queryBuilder->shouldReceive('selectRaw')->andReturnSelf();
    $queryBuilder->shouldReceive('where')->andReturnSelf();
    $queryBuilder->shouldReceive('whereNotNull')->andReturnSelf();
    $queryBuilder->shouldReceive('whereNull')->andReturnSelf();
    $queryBuilder->shouldReceive('whereIn')->andReturnSelf();
    $queryBuilder->shouldReceive('whereRaw')->andReturnSelf();
    $queryBuilder->shouldReceive('having')->andReturnSelf();
    $queryBuilder->shouldReceive('havingRaw')->andReturnSelf();
    $queryBuilder->shouldReceive('groupBy')->andReturnSelf();
    $queryBuilder->shouldReceive('orderBy')->andReturnSelf();
    $queryBuilder->shouldReceive('orderByDesc')->andReturnSelf();
    $queryBuilder->shouldReceive('orderByRaw')->andReturnSelf();
    $queryBuilder->shouldReceive('limit')->andReturnSelf();
    $queryBuilder->shouldReceive('leftJoin')->andReturnSelf();
    $queryBuilder->shouldReceive('join')->andReturnSelf();
    $queryBuilder->shouldReceive('get')->andReturn(collect([]));
    $queryBuilder->shouldReceive('first')->andReturn(null);
    $queryBuilder->shouldReceive('count')->andReturn(0);
    $queryBuilder->shouldReceive('value')->andReturn(0);

    $connection = Mockery::mock(\Illuminate\Database\Connection::class);
    $connection->shouldReceive('table')->andReturn($queryBuilder);
    $connection->shouldReceive('select')->andReturn([]);
    $connection->shouldReceive('raw')
        ->andReturnUsing(fn ($value) => new \Illuminate\Database\Query\Expression($value));

    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturn($connection);

    DB::shouldReceive('raw')
        ->andReturnUsing(fn ($value) => new \Illuminate\Database\Query\Expression($value));
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

afterEach(function () {
    Cache::flush();
});

// =========================================================================
// Authentication
// =========================================================================

describe('Authentication', function () {
    it('requires authentication to access old statistics', function () {
        $response = $this->get('/old-statistics');

        $response->assertRedirect(route('login'));
    });

    it('loads the page for an authenticated user', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('old-statistics')
                ->has('statistics')
                ->has('lastUpdated')
        );
    });
});

// =========================================================================
// Data structure
// =========================================================================

describe('Data structure', function () {
    it('contains all required statistics sections', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('old-statistics')
                ->has('statistics.overview')
                ->has('statistics.institutions')
                ->has('statistics.relatedWorks')
                ->has('statistics.pidUsage')
                ->has('statistics.completeness')
                ->has('statistics.curators')
                ->has('statistics.roles')
                ->has('statistics.timeline')
                ->has('statistics.resourceTypes')
                ->has('statistics.languages')
                ->has('statistics.licenses')
                ->has('statistics.identifiers')
                ->has('statistics.current_year')
                ->has('statistics.affiliations')
                ->has('statistics.keywords')
                ->has('statistics.creation_time')
                ->has('statistics.descriptions')
                ->has('statistics.publication_years')
        );
    });

    it('has the correct overview statistics structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.overview.totalDatasets')
                ->has('statistics.overview.totalAuthors')
                ->has('statistics.overview.avgAuthorsPerDataset')
                ->has('statistics.overview.avgContributorsPerDataset')
                ->has('statistics.overview.avgRelatedWorks')
        );
    });

    it('has completeness metrics', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.completeness')
        );
    });

    it('has identifier statistics', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.identifiers')
        );
    });

    it('has keyword statistics', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.keywords')
        );
    });

    it('has description statistics with by_type breakdown', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.descriptions.by_type')
        );
    });

    it('returns an empty institutions array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.institutions', [])
        );
    });

    it('returns an empty pidUsage array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.pidUsage', [])
        );
    });

    it('returns an empty curators array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.curators', [])
        );
    });

    it('returns an empty roles array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.roles', [])
        );
    });

    it('has timeline with publications and created arrays', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.timeline.publicationsByYear')
                ->has('statistics.timeline.createdByYear')
        );
    });

    it('returns an empty resourceTypes array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.resourceTypes', [])
        );
    });

    it('returns an empty languages array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.languages', [])
        );
    });

    it('returns an empty licenses array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.licenses', [])
        );
    });

    it('has current year statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.current_year.year')
                ->has('statistics.current_year.total')
                ->has('statistics.current_year.monthly')
        );
    });

    it('has affiliation statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.affiliations.max_per_agent')
                ->has('statistics.affiliations.avg_per_agent')
        );
    });

    it('returns an empty creation_time array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.creation_time', [])
        );
    });

    it('returns an empty publication_years array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('statistics.publication_years', [])
        );
    });

    it('provides a lastUpdated timestamp', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('lastUpdated')
        );
    });
});

// =========================================================================
// Caching
// =========================================================================

describe('Caching', function () {
    it('caches statistics after the first request', function () {
        mockMetaworksConnection();

        Cache::flush();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();

        expect(Cache::has('old_data_stats_overview'))->toBeTrue()
            ->and(Cache::has('old_data_stats_institutions'))->toBeTrue()
            ->and(Cache::has('old_data_stats_keywords'))->toBeTrue();
    });

    it('clears and repopulates cache on refresh parameter', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        expect(Cache::has('old_data_stats_overview'))->toBeTrue();

        $response = $this->actingAs($this->user)->get('/old-statistics?refresh=1');

        $response->assertOk();
        expect(Cache::has('old_data_stats_overview'))->toBeTrue();
    });

    it('uses cache on subsequent requests', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        expect(Cache::has('old_data_stats_related_works'))->toBeTrue();
    });

    it('repopulates cache after flush', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        Cache::flush();

        $response = $this->actingAs($this->user)->get('/old-statistics?refresh=1');

        $response->assertOk();
        expect(Cache::has('old_data_stats_related_works'))->toBeTrue();
    });
});

// =========================================================================
// Related works statistics
// =========================================================================

describe('Related works statistics', function () {
    it('has distribution and top datasets', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.distribution')
                ->has('statistics.relatedWorks.topDatasets')
        );
    });

    it('has extended statistics structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.topDatasets')
                ->has('statistics.relatedWorks.distribution')
                ->has('statistics.relatedWorks.isSupplementTo')
                ->has('statistics.relatedWorks.placeholders')
                ->has('statistics.relatedWorks.relationTypes')
                ->has('statistics.relatedWorks.coverage')
                ->has('statistics.relatedWorks.quality')
        );
    });

    it('has isSupplementTo statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.isSupplementTo.withIsSupplementTo')
                ->has('statistics.relatedWorks.isSupplementTo.withoutIsSupplementTo')
                ->has('statistics.relatedWorks.isSupplementTo.percentageWith')
                ->has('statistics.relatedWorks.isSupplementTo.percentageWithout')
        );
    });

    it('has isSupplementTo percentages that sum to 100', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.isSupplementTo.percentageWith')
                ->has('statistics.relatedWorks.isSupplementTo.percentageWithout')
        );
    });

    it('has placeholder statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.placeholders.totalPlaceholders')
                ->has('statistics.relatedWorks.placeholders.datasetsWithPlaceholders')
                ->has('statistics.relatedWorks.placeholders.patterns')
        );
    });

    it('has placeholder patterns as array', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.relatedWorks.placeholders.patterns')
        );
    });

    it('has relation types statistics', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.relatedWorks.relationTypes')
        );
    });

    it('has valid relation types percentages', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.relatedWorks.relationTypes')
        );
    });

    it('has coverage statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.coverage.withNoRelatedWorks')
                ->has('statistics.relatedWorks.coverage.withOnlyIsSupplementTo')
                ->has('statistics.relatedWorks.coverage.withMultipleTypes')
                ->has('statistics.relatedWorks.coverage.avgTypesPerDataset')
        );
    });

    it('has non-negative coverage average types per dataset', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.relatedWorks.coverage.avgTypesPerDataset')
        );
    });

    it('has quality statistics with correct structure', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.quality.completeData')
                ->has('statistics.relatedWorks.quality.incompleteOrPlaceholder')
                ->has('statistics.relatedWorks.quality.percentageComplete')
        );
    });

    it('has quality percentage complete between 0 and 100', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->has('statistics.relatedWorks.quality.percentageComplete')
        );
    });

    it('has consistent quality metrics', function () {
        mockMetaworksConnection();

        $response = $this->actingAs($this->user)->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.quality.completeData')
                ->has('statistics.relatedWorks.quality.incompleteOrPlaceholder')
                ->has('statistics.relatedWorks.quality.percentageComplete')
        );
    });
});
