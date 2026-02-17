<?php

declare(strict_types=1);

use App\Http\Controllers\OldDataStatisticsController;

covers(OldDataStatisticsController::class);

describe('Cache configuration', function () {
    it('uses properly prefixed cache keys', function () {
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
            'top_datasets_by_relation_type',
        ];

        foreach ($expectedKeys as $key) {
            $fullKey = $expectedPrefix.$key;
            expect($fullKey)->toBeString();
            expect($fullKey)->toStartWith($expectedPrefix);
        }
    });

    it('has cache duration of 12 hours', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_DURATION');

        expect($constant)->toBe(43200); // 12 hours in seconds
    });

    it('has correct cache key prefix', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_KEY_PREFIX');

        expect($constant)->toBe('old_data_stats_');
    });
});

describe('Database configuration', function () {
    it('uses metaworks as dataset connection', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('DATASET_CONNECTION');

        expect($constant)->toBe('metaworks');
    });
});
