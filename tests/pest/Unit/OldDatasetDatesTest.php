<?php

use App\Models\OldDataset;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * Test data based on actual old database entries (Dataset ID 3):
 * - Available: start=NULL, end="2017-03-01" (open-ended range)
 * - Created: start="2015-03-10", end=NULL (single date)
 * - Collected: start="2013-09-05", end="2014-10-11" (full range)
 */

afterEach(function () {
    Mockery::close();
});

test('getResourceDates returns empty array for non-existent dataset', function () {
    $dataset = new OldDataset();
    $dataset->exists = false;

    $dates = $dataset->getResourceDates();

    expect($dates)->toBeArray()->toBeEmpty();
});

test('getResourceDates returns single date format correctly', function () {
    // Dataset with single date (like "Created: 2015-03-10")
    $dataset = new OldDataset();
    $dataset->exists = true;
    $dataset->id = 999;

    DB::shouldReceive('connection')
        ->once()
        ->with('metaworks')
        ->andReturnSelf();

    DB::shouldReceive('table')
        ->once()
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->once()
        ->with('resource_id', 999)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->once()
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->once()
        ->andReturn(collect([
            (object) [
                'datetype' => 'Created',
                'start' => '2015-03-10',
                'end' => null,
            ],
        ]));

    $dates = $dataset->getResourceDates();

    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'created',
            'startDate' => '2015-03-10',
            'endDate' => '',
        ]);
});

test('getResourceDates returns full range format correctly', function () {
    // Dataset with full range (like "Collected: 2013-09-05/2014-10-11")
    $dataset = new OldDataset();
    $dataset->exists = true;
    $dataset->id = 998;

    DB::shouldReceive('connection')
        ->once()
        ->with('metaworks')
        ->andReturnSelf();

    DB::shouldReceive('table')
        ->once()
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->once()
        ->with('resource_id', 998)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->once()
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->once()
        ->andReturn(collect([
            (object) [
                'datetype' => 'Collected',
                'start' => '2013-09-05',
                'end' => '2014-10-11',
            ],
        ]));

    $dates = $dataset->getResourceDates();

    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'collected',
            'startDate' => '2013-09-05',
            'endDate' => '2014-10-11',
        ]);
});

test('getResourceDates returns open-ended range format correctly', function () {
    // Dataset with open-ended range (like "Available: /2017-03-01")
    $dataset = new OldDataset();
    $dataset->exists = true;
    $dataset->id = 997;

    DB::shouldReceive('connection')
        ->once()
        ->with('metaworks')
        ->andReturnSelf();

    DB::shouldReceive('table')
        ->once()
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->once()
        ->with('resource_id', 997)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->once()
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->once()
        ->andReturn(collect([
            (object) [
                'datetype' => 'Available',
                'start' => null,
                'end' => '2017-03-01',
            ],
        ]));

    $dates = $dataset->getResourceDates();

    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'available',
            'startDate' => '',
            'endDate' => '2017-03-01',
        ]);
});

test('getResourceDates returns all three date format types correctly', function () {
    // Simulate Dataset ID 3 with all three date types
    $dataset = new OldDataset();
    $dataset->exists = true;
    $dataset->id = 3;

    DB::shouldReceive('connection')
        ->once()
        ->with('metaworks')
        ->andReturnSelf();

    DB::shouldReceive('table')
        ->once()
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->once()
        ->with('resource_id', 3)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->once()
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->once()
        ->andReturn(collect([
            (object) [
                'datetype' => 'Available',
                'start' => null,
                'end' => '2017-03-01',
            ],
            (object) [
                'datetype' => 'Created',
                'start' => '2015-03-10',
                'end' => null,
            ],
            (object) [
                'datetype' => 'Collected',
                'start' => '2013-09-05',
                'end' => '2014-10-11',
            ],
        ]));

    $dates = $dataset->getResourceDates();

    expect($dates)->toHaveCount(3)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'available',
            'startDate' => '',
            'endDate' => '2017-03-01',
        ])
        ->and($dates[1])->toMatchArray([
            'dateType' => 'created',
            'startDate' => '2015-03-10',
            'endDate' => '',
        ])
        ->and($dates[2])->toMatchArray([
            'dateType' => 'collected',
            'startDate' => '2013-09-05',
            'endDate' => '2014-10-11',
        ]);
});

test('getResourceDates converts date type to lowercase', function () {
    // Test that capitalized date types from old DB are converted to lowercase
    $dataset = new OldDataset();
    $dataset->exists = true;
    $dataset->id = 996;

    DB::shouldReceive('connection')
        ->once()
        ->with('metaworks')
        ->andReturnSelf();

    DB::shouldReceive('table')
        ->once()
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->once()
        ->with('resource_id', 996)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->once()
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->once()
        ->andReturn(collect([
            (object) [
                'datetype' => 'Available',
                'start' => null,
                'end' => '2017-03-01',
            ],
            (object) [
                'datetype' => 'CREATED',
                'start' => '2015-03-10',
                'end' => null,
            ],
        ]));

    $dates = $dataset->getResourceDates();

    expect($dates)->toHaveCount(2)
        ->and($dates[0]['dateType'])->toBe('available')
        ->and($dates[1]['dateType'])->toBe('created');
});


