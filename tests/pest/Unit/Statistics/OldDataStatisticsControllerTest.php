<?php

declare(strict_types=1);

use App\Http\Controllers\OldDataStatisticsController;

describe('OldDataStatisticsController constants', function () {
    test('cache keys are properly prefixed', function () {
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
            $fullKey = $expectedPrefix . $key;
            expect($fullKey)
                ->toBeString()
                ->toStartWith($expectedPrefix);
        }
    });

    test('cache duration is 12 hours', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_DURATION');

        expect($constant)->toBe(43200);
    });

    test('dataset connection is metaworks', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('DATASET_CONNECTION');

        expect($constant)->toBe('metaworks');
    });

    test('cache key prefix is correct', function () {
        $reflection = new ReflectionClass(OldDataStatisticsController::class);
        $constant = $reflection->getConstant('CACHE_KEY_PREFIX');

        expect($constant)->toBe('old_data_stats_');
    });
});
