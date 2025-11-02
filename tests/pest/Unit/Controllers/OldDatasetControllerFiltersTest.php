<?php

declare(strict_types=1);

use App\Http\Controllers\OldDatasetController;
use Illuminate\Http\Request;

// Test helper to access protected methods
class TestableOldDatasetController extends OldDatasetController
{
    public function testExtractFilters(Request $request): array
    {
        return $this->extractFilters($request);
    }

    public function testResolveSortState(Request $request): array
    {
        return $this->resolveSortState($request);
    }
}

describe('OldDatasetController - Filter Extraction', function () {
    beforeEach(function () {
        $this->controller = new TestableOldDatasetController;
    });

    it('extracts empty filters from empty request', function () {
        $request = Request::create('/old-datasets', 'GET');

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toBeArray();
        expect($filters)->toBeEmpty();
    });

    it('extracts search filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'search' => 'database',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('search');
        expect($filters['search'])->toBe('database');
    });

    it('extracts single status filter and converts to array', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'status' => 'pending',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('status');
        expect($filters['status'])->toBeArray();
        expect($filters['status'])->toBe(['pending']);
    });

    it('extracts array status filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'status' => ['pending', 'released'],
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('status');
        expect($filters['status'])->toBeArray();
        expect($filters['status'])->toBe(['pending', 'released']);
    });

    it('extracts single curator filter and converts to array', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'curator' => 'admin',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('curator');
        expect($filters['curator'])->toBeArray();
        expect($filters['curator'])->toBe(['admin']);
    });

    it('extracts array curator filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'curator' => ['admin', 'kelger'],
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('curator');
        expect($filters['curator'])->toBeArray();
        expect($filters['curator'])->toBe(['admin', 'kelger']);
    });

    it('extracts single resource_type filter and converts to array', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'resource_type' => 'Dataset',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('resource_type');
        expect($filters['resource_type'])->toBeArray();
        expect($filters['resource_type'])->toBe(['Dataset']);
    });

    it('extracts year_from filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'year_from' => 2020,
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('year_from');
        expect($filters['year_from'])->toBe(2020);
    });

    it('extracts year_to filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'year_to' => 2023,
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('year_to');
        expect($filters['year_to'])->toBe(2023);
    });

    it('extracts created_from date filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'created_from' => '2024-01-01',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('created_from');
        expect($filters['created_from'])->toBe('2024-01-01');
    });

    it('extracts created_to date filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'created_to' => '2024-12-31',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('created_to');
        expect($filters['created_to'])->toBe('2024-12-31');
    });

    it('extracts updated_from date filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'updated_from' => '2025-01-01',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('updated_from');
        expect($filters['updated_from'])->toBe('2025-01-01');
    });

    it('extracts updated_to date filter', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'updated_to' => '2025-12-31',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('updated_to');
        expect($filters['updated_to'])->toBe('2025-12-31');
    });

    it('extracts multiple filters simultaneously', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'search' => 'database',
            'status' => ['pending'],
            'curator' => ['admin'],
            'resource_type' => ['Dataset'],
            'year_from' => 2020,
            'year_to' => 2023,
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toHaveKey('search');
        expect($filters)->toHaveKey('status');
        expect($filters)->toHaveKey('curator');
        expect($filters)->toHaveKey('resource_type');
        expect($filters)->toHaveKey('year_from');
        expect($filters)->toHaveKey('year_to');

        expect($filters['search'])->toBe('database');
        expect($filters['status'])->toBe(['pending']);
        expect($filters['curator'])->toBe(['admin']);
        expect($filters['resource_type'])->toBe(['Dataset']);
        expect($filters['year_from'])->toBe(2020);
        expect($filters['year_to'])->toBe(2023);
    });

    it('ignores unknown filter parameters', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'unknown_param' => 'value',
            'another_unknown' => 'test',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toBeEmpty();
    });

    it('handles empty string filters gracefully', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'search' => '',
            'status' => '',
        ]);

        $filters = $this->controller->testExtractFilters($request);

        // Empty strings should not be included
        expect($filters)->toBeEmpty();
    });

    it('handles null filters gracefully', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'search' => null,
            'curator' => null,
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters)->toBeEmpty();
    });

    it('converts numeric year_from to integer', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'year_from' => '2020', // String number
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters['year_from'])->toBeInt();
        expect($filters['year_from'])->toBe(2020);
    });

    it('converts numeric year_to to integer', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'year_to' => '2023', // String number
        ]);

        $filters = $this->controller->testExtractFilters($request);

        expect($filters['year_to'])->toBeInt();
        expect($filters['year_to'])->toBe(2023);
    });
});

describe('OldDatasetController - Sort State Resolution', function () {
    it('resolves default sort state', function () {
        $request = Request::create('/old-datasets', 'GET');

        $controller = new TestableOldDatasetController;
        [$sortKey, $sortDirection] = $controller->testResolveSortState($request);

        expect($sortKey)->toBe('updated_at');
        expect($sortDirection)->toBe('desc');
    });

    it('resolves custom sort state', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'sort_key' => 'title',
            'sort_direction' => 'asc',
        ]);

        $controller = new TestableOldDatasetController;
        [$sortKey, $sortDirection] = $controller->testResolveSortState($request);

        expect($sortKey)->toBe('title');
        expect($sortDirection)->toBe('asc');
    });

    it('validates sort direction to asc or desc', function () {
        $request = Request::create('/old-datasets', 'GET', [
            'sort_key' => 'title',
            'sort_direction' => 'invalid',
        ]);

        $controller = new TestableOldDatasetController;
        [$sortKey, $sortDirection] = $controller->testResolveSortState($request);

        expect($sortDirection)->toBeIn(['asc', 'desc']);
    });
});
