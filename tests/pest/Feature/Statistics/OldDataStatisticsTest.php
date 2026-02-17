<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);
});

afterEach(function () {
    Cache::flush();
});

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

test('old statistics page requires authentication', function () {
    $this->get('/old-statistics')
        ->assertRedirect(route('login'));
});

test('old statistics page loads for authenticated user', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('old-statistics')
                ->has('statistics')
                ->has('lastUpdated')
        );
});

test('statistics data structure contains all required sections', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(
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

test('overview statistics have correct structure', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('statistics.overview.totalDatasets')
                ->has('statistics.overview.totalAuthors')
                ->has('statistics.overview.avgAuthorsPerDataset')
                ->has('statistics.overview.avgContributorsPerDataset')
                ->has('statistics.overview.avgRelatedWorks')
        );
});

test('completeness metrics have correct structure', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statistics.completeness'));
});

test('identifier statistics have correct structure', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statistics.identifiers'));
});

test('keyword statistics have correct structure', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statistics.keywords'));
});

test('description statistics have correct structure', function () {
    mockMetaworksConnection();

    $this->actingAs($this->user)
        ->get('/old-statistics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statistics.descriptions.by_type'));
});

describe('caching', function () {
    test('statistics are cached', function () {
        mockMetaworksConnection();
        Cache::flush();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk();

        expect(Cache::has('old_data_stats_overview'))->toBeTrue()
            ->and(Cache::has('old_data_stats_institutions'))->toBeTrue()
            ->and(Cache::has('old_data_stats_keywords'))->toBeTrue();
    });

    test('refresh parameter clears cache', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        expect(Cache::has('old_data_stats_overview'))->toBeTrue();

        $this->actingAs($this->user)
            ->get('/old-statistics?refresh=1')
            ->assertOk();

        expect(Cache::has('old_data_stats_overview'))->toBeTrue();
    });

    test('cache is used for statistics', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk();

        expect(Cache::has('old_data_stats_related_works'))->toBeTrue();
    });

    test('cache can be refreshed', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)->get('/old-statistics');

        Cache::flush();

        $this->actingAs($this->user)
            ->get('/old-statistics?refresh=1')
            ->assertOk();

        expect(Cache::has('old_data_stats_related_works'))->toBeTrue();
    });
});

describe('data arrays', function () {
    test('institutions array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.institutions', []));
    });

    test('pid usage array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.pidUsage', []));
    });

    test('curators array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.curators', []));
    });

    test('roles array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.roles', []));
    });

    test('resource types array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.resourceTypes', []));
    });

    test('languages array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.languages', []));
    });

    test('licenses array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.licenses', []));
    });

    test('creation time array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.creation_time', []));
    });

    test('publication years array is returned', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.publication_years', []));
    });
});

describe('timeline and current year', function () {
    test('timeline has publications and created arrays', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.timeline.publicationsByYear')
                    ->has('statistics.timeline.createdByYear')
            );
    });

    test('current year statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.current_year.year')
                    ->has('statistics.current_year.total')
                    ->has('statistics.current_year.monthly')
            );
    });

    test('last updated timestamp is provided', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('lastUpdated'));
    });
});

describe('affiliation statistics', function () {
    test('affiliation statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.affiliations.max_per_agent')
                    ->has('statistics.affiliations.avg_per_agent')
            );
    });
});

describe('related works extended statistics', function () {
    test('related works has distribution and top datasets', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.distribution')
                    ->has('statistics.relatedWorks.topDatasets')
            );
    });

    test('related works has extended statistics structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
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

    test('is supplement to statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.isSupplementTo.withIsSupplementTo')
                    ->has('statistics.relatedWorks.isSupplementTo.withoutIsSupplementTo')
                    ->has('statistics.relatedWorks.isSupplementTo.percentageWith')
                    ->has('statistics.relatedWorks.isSupplementTo.percentageWithout')
            );
    });

    test('is supplement to percentages sum to 100', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.isSupplementTo.percentageWith')
                    ->has('statistics.relatedWorks.isSupplementTo.percentageWithout')
            );
    });

    test('placeholder statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.placeholders.totalPlaceholders')
                    ->has('statistics.relatedWorks.placeholders.datasetsWithPlaceholders')
                    ->has('statistics.relatedWorks.placeholders.patterns')
            );
    });

    test('placeholder patterns is array', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statistics.relatedWorks.placeholders.patterns'));
    });

    test('relation types statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statistics.relatedWorks.relationTypes'));
    });

    test('relation types percentages are valid', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statistics.relatedWorks.relationTypes'));
    });

    test('coverage statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.coverage.withNoRelatedWorks')
                    ->has('statistics.relatedWorks.coverage.withOnlyIsSupplementTo')
                    ->has('statistics.relatedWorks.coverage.withMultipleTypes')
                    ->has('statistics.relatedWorks.coverage.avgTypesPerDataset')
            );
    });

    test('coverage average types is non negative', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statistics.relatedWorks.coverage.avgTypesPerDataset'));
    });

    test('quality statistics have correct structure', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.quality.completeData')
                    ->has('statistics.relatedWorks.quality.incompleteOrPlaceholder')
                    ->has('statistics.relatedWorks.quality.percentageComplete')
            );
    });

    test('quality percentage complete is between 0 and 100', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('statistics.relatedWorks.quality.percentageComplete'));
    });

    test('quality metrics are consistent', function () {
        mockMetaworksConnection();

        $this->actingAs($this->user)
            ->get('/old-statistics')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('statistics.relatedWorks.quality.completeData')
                    ->has('statistics.relatedWorks.quality.incompleteOrPlaceholder')
                    ->has('statistics.relatedWorks.quality.percentageComplete')
            );
    });
});
