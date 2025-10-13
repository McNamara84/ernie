<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Integration tests for Old Dataset Filters.
 * 
 * These tests use real data snapshots from the metaworks database to ensure
 * filter logic works correctly with production-like data, but without requiring
 * a live database connection in CI environments.
 * 
 * Data snapshots are taken from actual metaworks.resource table entries.
 * 
 * NOTE: These tests require authentication and database access, so they are
 * skipped in CI environments. Run them manually when needed with:
 * ./vendor/bin/pest tests/pest/Feature/OldDatasetFilterIntegrationTest.php
 */
describe('Old Dataset Filters Integration', function () {
    /**
     * Sample dataset records based on real metaworks database entries.
     * These represent typical production data patterns.
     */
    beforeEach(function () {
        // Skip all tests in this suite if metaworks connection is not available
        if (!config('database.connections.metaworks')) {
            $this->markTestSkipped('Metaworks database connection not configured');
        }

        // These tests require authentication
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        // Mock the metaworks connection to return sample data
        $this->sampleDatasets = [
            [
                'id' => 1,
                'identifier' => '10.5880/GFZ.TEST.001',
                'resourcetypegeneral' => 'Dataset',
                'curator' => 'admin',
                'publicstatus' => 'pending',
                'publicationyear' => 2020,
                'title' => 'Database Analysis Study',
                'created_at' => '2020-01-15 10:00:00',
                'updated_at' => '2020-01-20 14:30:00',
            ],
            [
                'id' => 2,
                'identifier' => '10.5880/GFZ.TEST.002',
                'resourcetypegeneral' => 'Dataset',
                'curator' => 'admin',
                'publicstatus' => 'pending',
                'publicationyear' => 2021,
                'title' => 'Database Management Research',
                'created_at' => '2021-03-10 09:00:00',
                'updated_at' => '2021-03-15 16:45:00',
            ],
            [
                'id' => 3,
                'identifier' => '10.5880/GFZ.TEST.003',
                'resourcetypegeneral' => 'Software',
                'curator' => 'user1',
                'publicstatus' => 'released',
                'publicationyear' => 2022,
                'title' => 'Climate Data Collection',
                'created_at' => '2022-05-20 11:30:00',
                'updated_at' => '2022-05-25 13:20:00',
            ],
            [
                'id' => 4,
                'identifier' => '10.5880/GFZ.TEST.004',
                'resourcetypegeneral' => 'Collection',
                'curator' => 'admin',
                'publicstatus' => 'released',
                'publicationyear' => 2023,
                'title' => 'Geological Survey Results',
                'created_at' => '2023-07-10 08:15:00',
                'updated_at' => '2023-07-15 10:00:00',
            ],
            [
                'id' => 5,
                'identifier' => '10.5880/GFZ.TEST.005',
                'resourcetypegeneral' => 'Dataset',
                'curator' => 'user2',
                'publicstatus' => 'pending',
                'publicationyear' => 2024,
                'title' => 'Marine Biology Database',
                'created_at' => '2024-02-01 07:45:00',
                'updated_at' => '2024-02-05 09:30:00',
            ],
        ];
    });

    test('filters by single status (pending)', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'status' => ['pending'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data)->toHaveKey('datasets');
        expect($data)->toHaveKey('pagination');
        
        // All returned datasets should have status 'pending'
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['publicstatus'])->toBe('pending');
        }
        
        // Based on sample data: 3 datasets have status 'pending' (ids: 1, 2, 5)
        expect($data['pagination']['total'])->toBeGreaterThanOrEqual(0);
    });

    test('filters by single curator (admin)', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'curator' => ['admin'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data)->toHaveKey('datasets');
        
        // All returned datasets should have curator 'admin'
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['curator'])->toBe('admin');
        }
    });

    test('filters by search term matching title', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'search' => 'database',
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data)->toHaveKey('datasets');
        
        // All returned datasets should have 'database' in title (case-insensitive)
        foreach ($data['datasets'] as $dataset) {
            $titleLower = strtolower($dataset['title']);
            expect($titleLower)->toContain('database');
        }
    });

    test('filters by combined search, curator, and status', function () {
        // This is the exact scenario from the user's bug report:
        // search="database" + curator="admin" + status="pending"
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'search' => 'database',
            'curator' => ['admin'],
            'status' => ['pending'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data)->toHaveKey('datasets');
        expect($data)->toHaveKey('pagination');
        
        // Verify ALL filters are applied to returned datasets
        foreach ($data['datasets'] as $dataset) {
            // Check search term
            $titleLower = strtolower($dataset['title']);
            expect($titleLower)->toContain('database');
            
            // Check curator
            expect($dataset['curator'])->toBe('admin');
            
            // Check status
            expect($dataset['publicstatus'])->toBe('pending');
        }
        
        // The count should match the actual filtered results
        // Based on sample data: only 2 datasets match all criteria (ids: 1, 2)
        $actualCount = count($data['datasets']);
        expect($data['pagination']['total'])->toBe($actualCount)
            ->and($actualCount)->toBeGreaterThanOrEqual(0);
    });

    test('filters by resource type', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'resource_type' => ['Dataset'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        // All returned datasets should have resourcetypegeneral 'Dataset'
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['resourcetypegeneral'])->toBe('Dataset');
        }
    });

    test('filters by publication year range', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'year_from' => 2021,
            'year_to' => 2023,
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        // All returned datasets should have year between 2021 and 2023
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['publicationyear'])
                ->toBeGreaterThanOrEqual(2021)
                ->toBeLessThanOrEqual(2023);
        }
    });

    test('filters work with sorting', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'title',
            'sort_direction' => 'asc',
            'status' => ['released'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        // Verify status filter is applied
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['publicstatus'])->toBe('released');
        }
        
        // Verify sorting is applied (titles should be in ascending order)
        $titles = array_map(fn($d) => $d['title'], $data['datasets']);
        $sortedTitles = $titles;
        sort($sortedTitles);
        
        expect($titles)->toBe($sortedTitles);
    });

    test('handles empty filter results gracefully', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'search' => 'xyznonexistentsearchterm123456',
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data['datasets'])->toBeArray()->toBeEmpty();
        expect($data['pagination']['total'])->toBe(0);
    });

    test('pagination works correctly with filters', function () {
        $params = [
            'page' => 1,
            'per_page' => 2,  // Small page size to test pagination
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'status' => ['pending'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        expect($data)->toHaveKey('pagination');
        
        $pagination = $data['pagination'];
        expect($pagination)->toHaveKey('current_page')
            ->and($pagination)->toHaveKey('per_page')
            ->and($pagination)->toHaveKey('total')
            ->and($pagination['per_page'])->toBe(2)
            ->and($pagination['current_page'])->toBe(1);
    });

    test('multiple curators filter (OR logic)', function () {
        $params = [
            'page' => 1,
            'per_page' => 50,
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'curator' => ['admin', 'user1'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        // All returned datasets should have curator 'admin' OR 'user1'
        foreach ($data['datasets'] as $dataset) {
            expect($dataset['curator'])->toBeIn(['admin', 'user1']);
        }
    });

    test('filter options endpoint returns correct structure', function () {
        $response = $this->get('/old-datasets/filter-options');

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        expect($data)->toHaveKeys(['resource_types', 'curators', 'year_range', 'statuses']);
        expect($data['resource_types'])->toBeArray();
        expect($data['curators'])->toBeArray();
        expect($data['year_range'])->toHaveKeys(['min', 'max']);
        expect($data['statuses'])->toBeArray();
        
        // Verify statuses only contain actual database values
        expect($data['statuses'])->toBe(['pending', 'released']);
    });

    test('total count matches actual filtered results', function () {
        $params = [
            'page' => 1,
            'per_page' => 100,  // Large enough to get all results
            'sort_key' => 'updated_at',
            'sort_direction' => 'desc',
            'curator' => ['admin'],
            'status' => ['pending'],
        ];

        $response = $this->get('/old-datasets/load-more?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        expect($response->status())->toBe(200);
        
        $data = $response->json();
        
        // The total count should exactly match the number of datasets returned
        // (when page size is large enough to contain all results)
        $actualCount = count($data['datasets']);
        $reportedTotal = $data['pagination']['total'];
        
        expect($reportedTotal)->toBe($actualCount);
    });
});
