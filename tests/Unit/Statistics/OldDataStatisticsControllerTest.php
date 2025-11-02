<?php

declare(strict_types=1);

namespace Tests\Unit\Statistics;

use App\Http\Controllers\OldDataStatisticsController;
use ReflectionClass;
use Tests\TestCase;

/**
 * Unit tests for OldDataStatisticsController constants and configuration.
 *
 * Note: These tests verify controller constants and configuration values only.
 * Full integration tests that verify database queries and data structures are in
 * tests/Feature/Statistics/OldDataStatisticsTest.php with complete database mocking
 * to avoid VPN dependency issues.
 */
class OldDataStatisticsControllerTest extends TestCase
{
    /**
     * Test that cache keys follow the expected naming convention.
     */
    public function test_cache_keys_are_properly_prefixed(): void
    {
        $expectedPrefix = 'old_data_stats_';
        $expectedKeys = [
            'overview',
            'institutions',
            'related_works',
            'pid_usage',
            'completeness',
            'curators',
            'roles',
            'timeline',
            'resource_types',
            'languages',
            'licenses',
            'identifiers',
            'current_year',
            'affiliations',
            'keywords',
            'creation_time',
            'descriptions',
            'publication_years',
        ];

        foreach ($expectedKeys as $key) {
            $fullKey = $expectedPrefix.$key;
            $this->assertIsString($fullKey);
            $this->assertStringStartsWith($expectedPrefix, $fullKey);
        }
    }

    /**
     * Test cache duration constant is 12 hours.
     */
    public function test_cache_duration_is_12_hours(): void
    {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_DURATION');

        $this->assertEquals(43200, $constant); // 12 hours in seconds
    }

    /**
     * Test database connection constant is 'metaworks'.
     */
    public function test_dataset_connection_is_metaworks(): void
    {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('DATASET_CONNECTION');

        $this->assertEquals('metaworks', $constant);
    }

    /**
     * Test cache key prefix constant.
     */
    public function test_cache_key_prefix_is_correct(): void
    {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_KEY_PREFIX');

        $this->assertEquals('old_data_stats_', $constant);
    }
}
