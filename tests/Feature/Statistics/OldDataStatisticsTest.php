<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class OldDataStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Mock the metaworks database connection to prevent actual database queries.
     */
    private function mockMetaworksConnection(): void
    {
        // Create a mock query builder
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

        // Mock the connection
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('table')->andReturn($queryBuilder);

        // Mock select() for raw SQL queries
        $connection->shouldReceive('select')->andReturn([]);

        // Mock raw() to return Expression objects
        $connection->shouldReceive('raw')
            ->andReturnUsing(function ($value) {
                return new \Illuminate\Database\Query\Expression($value);
            });

        // Mock the DB facade
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturn($connection);

        // Also mock DB::raw() calls directly on the facade
        DB::shouldReceive('raw')
            ->andReturnUsing(function ($value) {
                return new \Illuminate\Database\Query\Expression($value);
            });
    }

    public function test_old_statistics_page_requires_authentication(): void
    {
        $response = $this->get('/old-statistics');

        $response->assertRedirect(route('login'));
    }

    public function test_old_statistics_page_loads_for_authenticated_user(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('old-statistics')
                ->has('statistics')
                ->has('lastUpdated')
        );
    }

    public function test_statistics_data_structure_contains_all_required_sections(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

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
    }

    public function test_overview_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.overview.totalDatasets')
                ->has('statistics.overview.totalAuthors')
                ->has('statistics.overview.avgAuthorsPerDataset')
                ->has('statistics.overview.avgContributorsPerDataset')
                ->has('statistics.overview.avgRelatedWorks')
        );
    }

    public function test_completeness_metrics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.completeness')
        );
    }

    public function test_identifier_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.identifiers')
        );
    }

    public function test_keyword_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.keywords')
        );
    }

    public function test_description_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.descriptions.by_type')
        );
    }

    public function test_statistics_are_cached(): void
    {
        $this->mockMetaworksConnection();

        // Clear cache first
        Cache::flush();

        // First request should hit the database
        $response1 = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response1->assertOk();

        // Check that cache was set (cache keys should exist)
        $this->assertTrue(Cache::has('old_data_stats_overview'));
        $this->assertTrue(Cache::has('old_data_stats_institutions'));
        $this->assertTrue(Cache::has('old_data_stats_keywords'));
    }

    public function test_refresh_parameter_clears_cache(): void
    {
        $this->mockMetaworksConnection();

        // First request to populate cache
        $this->actingAs($this->user)
            ->get('/old-statistics');

        $this->assertTrue(Cache::has('old_data_stats_overview'));

        // Request with refresh parameter should clear cache
        $response = $this->actingAs($this->user)
            ->get('/old-statistics?refresh=1');

        $response->assertOk();

        // Cache should be repopulated after refresh
        $this->assertTrue(Cache::has('old_data_stats_overview'));
    }

    public function test_institutions_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.institutions', [])
        );
    }

    public function test_related_works_has_distribution_and_top_datasets(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.relatedWorks.distribution')
                ->has('statistics.relatedWorks.topDatasets')
        );
    }

    public function test_pid_usage_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.pidUsage', [])
        );
    }

    public function test_curators_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.curators', [])
        );
    }

    public function test_roles_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.roles', [])
        );
    }

    public function test_timeline_has_publications_and_created_arrays(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.timeline.publicationsByYear')
                ->has('statistics.timeline.createdByYear')
        );
    }

    public function test_resource_types_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.resourceTypes', [])
        );
    }

    public function test_languages_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.languages', [])
        );
    }

    public function test_licenses_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.licenses', [])
        );
    }

    public function test_current_year_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.current_year.year')
                ->has('statistics.current_year.total')
                ->has('statistics.current_year.monthly')
        );
    }

    public function test_affiliation_statistics_have_correct_structure(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('statistics.affiliations.max_per_agent')
                ->has('statistics.affiliations.avg_per_agent')
        );
    }

    public function test_creation_time_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.creation_time', [])
        );
    }

    public function test_publication_years_array_is_returned(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->where('statistics.publication_years', [])
        );
    }

    public function test_last_updated_timestamp_is_provided(): void
    {
        $this->mockMetaworksConnection();

        $response = $this->actingAs($this->user)
            ->get('/old-statistics');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->has('lastUpdated')
        );
    }
}
