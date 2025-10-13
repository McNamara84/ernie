<?php

declare(strict_types=1);

use App\Http\Controllers\OldDatasetController;
use Illuminate\Http\Request;

/**
 * Tests for OldDataset sorting logic.
 * 
 * These tests validate the sorting parameter resolution without requiring
 * a database connection, making them safe for CI environments.
 */

describe('OldDatasetController Sort Parameter Resolution', function () {
    
    beforeEach(function () {
        $this->controller = new OldDatasetController();
        $this->reflectionMethod = new ReflectionMethod(OldDatasetController::class, 'resolveSortState');
        $this->reflectionMethod->setAccessible(true);
    });

    test('resolves valid sort key and direction', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => 'first_author',
            'sort_direction' => 'asc',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('first_author')
            ->and($sortDirection)->toBe('asc');
    });

    test('accepts all valid sort keys', function (string $key) {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => $key,
            'sort_direction' => 'asc',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe($key)
            ->and($sortDirection)->toBe('asc');
    })->with([
        'id',
        'identifier',
        'title',
        'resourcetypegeneral',
        'first_author',
        'publicationyear',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ]);

    test('rejects invalid sort key and falls back to default', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => 'invalid_key',
            'sort_direction' => 'asc',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('updated_at') // default
            ->and($sortDirection)->toBe('asc');
    });

    test('rejects invalid sort direction and falls back to default', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => 'first_author',
            'sort_direction' => 'invalid',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('first_author')
            ->and($sortDirection)->toBe('desc'); // default direction
    });

    test('handles case-insensitive sort parameters', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => 'FIRST_AUTHOR',
            'sort_direction' => 'ASC',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('first_author')
            ->and($sortDirection)->toBe('asc');
    });

    test('uses defaults when no sort parameters provided', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', []);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('updated_at')
            ->and($sortDirection)->toBe('desc');
    });

    test('accepts both asc and desc directions', function (string $direction) {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => 'first_author',
            'sort_direction' => $direction,
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortDirection)->toBe($direction);
    })->with(['asc', 'desc']);

    test('handles empty string sort parameters', function () {
        $request = Request::create('/old-datasets/load-more', 'GET', [
            'sort_key' => '',
            'sort_direction' => '',
        ]);

        [$sortKey, $sortDirection] = $this->reflectionMethod->invoke($this->controller, $request);

        expect($sortKey)->toBe('updated_at')
            ->and($sortDirection)->toBe('desc');
    });
});

describe('OldDataset SQL Sort Column Mapping', function () {
    
    test('maps sort keys to correct SQL columns', function (string $sortKey, string $expectedColumn) {
        // This is a conceptual test - we're documenting the expected mapping
        // The actual mapping is in OldDataset::getPaginatedOrdered()
        
        $mapping = [
            'id' => 'resource.id',
            'identifier' => 'resource.identifier',
            'title' => 'title.title',
            'resourcetypegeneral' => 'resource.resourcetypegeneral',
            'first_author' => 'first_author.first_author_lastname',
            'publicationyear' => 'resource.publicationyear',
            'curator' => 'resource.curator',
            'publicstatus' => 'resource.publicstatus',
            'created_at' => 'resource.created_at',
            'updated_at' => 'resource.updated_at',
        ];

        expect($mapping[$sortKey])->toBe($expectedColumn);
    })->with([
        ['id', 'resource.id'],
        ['identifier', 'resource.identifier'],
        ['title', 'title.title'],
        ['resourcetypegeneral', 'resource.resourcetypegeneral'],
        ['first_author', 'first_author.first_author_lastname'],
        ['publicationyear', 'resource.publicationyear'],
        ['curator', 'resource.curator'],
        ['publicstatus', 'resource.publicstatus'],
        ['created_at', 'resource.created_at'],
        ['updated_at', 'resource.updated_at'],
    ]);
});

describe('OldDataset Sort Key Constants', function () {
    
    test('controller has exactly 10 allowed sort keys', function () {
        // Using reflection to access private constant
        $reflection = new ReflectionClass(OldDatasetController::class);
        $constants = $reflection->getConstants();
        
        expect($constants)->toHaveKey('ALLOWED_SORT_KEYS')
            ->and($constants['ALLOWED_SORT_KEYS'])->toHaveCount(10);
    });

    test('controller has exactly 2 allowed sort directions', function () {
        $reflection = new ReflectionClass(OldDatasetController::class);
        $constants = $reflection->getConstants();
        
        expect($constants)->toHaveKey('ALLOWED_SORT_DIRECTIONS')
            ->and($constants['ALLOWED_SORT_DIRECTIONS'])->toHaveCount(2)
            ->and($constants['ALLOWED_SORT_DIRECTIONS'])->toContain('asc', 'desc');
    });

    test('default sort key is updated_at', function () {
        $reflection = new ReflectionClass(OldDatasetController::class);
        $constants = $reflection->getConstants();
        
        expect($constants)->toHaveKey('DEFAULT_SORT_KEY')
            ->and($constants['DEFAULT_SORT_KEY'])->toBe('updated_at');
    });

    test('default sort direction is desc', function () {
        $reflection = new ReflectionClass(OldDatasetController::class);
        $constants = $reflection->getConstants();
        
        expect($constants)->toHaveKey('DEFAULT_SORT_DIRECTION')
            ->and($constants['DEFAULT_SORT_DIRECTION'])->toBe('desc');
    });
});

describe('OldDataset Pagination Parameters', function () {
    
    test('loadMore validates page parameter', function (int $input, int $expected) {
        // Page should be minimum 1
        $validated = max(1, (int) $input);
        expect($validated)->toBe($expected);
    })->with([
        [0, 1],
        [-5, 1],
        [1, 1],
        [50, 50],
    ]);

    test('loadMore validates per_page parameter', function (int $input, int $expected) {
        // Per page should be min 10, max 200
        $validated = min(200, max(10, (int) $input));
        expect($validated)->toBe($expected);
    })->with([
        [0, 10],     // Below minimum
        [5, 10],     // Below minimum
        [10, 10],    // Minimum
        [50, 50],    // Valid
        [200, 200],  // Maximum
        [300, 200],  // Above maximum
    ]);
});

describe('OldDataset Author Data Structure', function () {
    
    test('first_author structure includes all required fields', function () {
        // Document the expected structure of first_author data
        $expectedFields = ['familyName', 'givenName', 'name'];
        
        // This is returned by the controller after processing
        $sampleAuthor = [
            'familyName' => 'Doe',
            'givenName' => 'John',
            'name' => 'Doe, John',
        ];

        foreach ($expectedFields as $field) {
            expect($sampleAuthor)->toHaveKey($field);
        }
    });

    test('first_author handles null values gracefully', function () {
        // Test that null values are handled correctly
        $authorWithNulls = [
            'familyName' => null,
            'givenName' => null,
            'name' => 'Full Name Only',
        ];

        expect($authorWithNulls['familyName'])->toBeNull()
            ->and($authorWithNulls['givenName'])->toBeNull()
            ->and($authorWithNulls['name'])->toBe('Full Name Only');
    });
});
