<?php

use Illuminate\Support\Facades\DB;
use function Pest\Laravel\getJson;

/**
 * Feature tests for OldDatasetController::getDates() endpoint.
 * These tests verify the API endpoint returns dates in the correct format
 * based on actual data from the old database (Dataset ID 3).
 */

test('getDates endpoint returns 404 for non-existent dataset', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock that no resource exists with this ID
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 999999)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn(null);

    $response = getJson('/old-datasets/999999/dates');

    $response->assertStatus(404)
        ->assertJson([
            'error' => 'Dataset not found',
        ]);
});

test('getDates endpoint returns dates for dataset with single date', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock resource exists
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 1)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn((object) ['id' => 1]);

    // Mock date table query
    DB::shouldReceive('table')
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('resource_id', 1)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->andReturn(collect([
            (object) [
                'datetype' => 'Created',
                'start' => '2015-03-10',
                'end' => null,
            ],
        ]));

    $response = getJson('/old-datasets/1/dates');

    $response->assertStatus(200)
        ->assertJson([
            'dates' => [
                [
                    'dateType' => 'created',
                    'startDate' => '2015-03-10',
                    'endDate' => '',
                ],
            ],
        ]);
});

test('getDates endpoint returns dates for dataset with full range', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock resource exists
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 2)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn((object) ['id' => 2]);

    // Mock date table query
    DB::shouldReceive('table')
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('resource_id', 2)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->andReturn(collect([
            (object) [
                'datetype' => 'Collected',
                'start' => '2013-09-05',
                'end' => '2014-10-11',
            ],
        ]));

    $response = getJson('/old-datasets/2/dates');

    $response->assertStatus(200)
        ->assertJson([
            'dates' => [
                [
                    'dateType' => 'collected',
                    'startDate' => '2013-09-05',
                    'endDate' => '2014-10-11',
                ],
            ],
        ]);
});

test('getDates endpoint returns dates for dataset with open-ended range', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock resource exists
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 3)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn((object) ['id' => 3]);

    // Mock date table query
    DB::shouldReceive('table')
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('resource_id', 3)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->andReturn(collect([
            (object) [
                'datetype' => 'Available',
                'start' => null,
                'end' => '2017-03-01',
            ],
        ]));

    $response = getJson('/old-datasets/3/dates');

    $response->assertStatus(200)
        ->assertJson([
            'dates' => [
                [
                    'dateType' => 'available',
                    'startDate' => '',
                    'endDate' => '2017-03-01',
                ],
            ],
        ]);
});

test('getDates endpoint returns all three date format types correctly', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock resource exists
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 3)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn((object) ['id' => 3]);

    // Mock date table query - all three format types
    DB::shouldReceive('table')
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('resource_id', 3)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
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

    $response = getJson('/old-datasets/3/dates');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'dates')
        ->assertJson([
            'dates' => [
                [
                    'dateType' => 'available',
                    'startDate' => '',
                    'endDate' => '2017-03-01',
                ],
                [
                    'dateType' => 'created',
                    'startDate' => '2015-03-10',
                    'endDate' => '',
                ],
                [
                    'dateType' => 'collected',
                    'startDate' => '2013-09-05',
                    'endDate' => '2014-10-11',
                ],
            ],
        ]);
});

test('getDates endpoint returns empty array for dataset without dates', function () {
    // Mock the metaworks database connection
    DB::shouldReceive('connection')
        ->with('metaworks')
        ->andReturnSelf();

    // Mock resource exists
    DB::shouldReceive('table')
        ->with('resource')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('id', 100)
        ->andReturnSelf();

    DB::shouldReceive('first')
        ->andReturn((object) ['id' => 100]);

    // Mock date table query - no dates
    DB::shouldReceive('table')
        ->with('date')
        ->andReturnSelf();

    DB::shouldReceive('where')
        ->with('resource_id', 100)
        ->andReturnSelf();

    DB::shouldReceive('select')
        ->with('datetype', 'start', 'end')
        ->andReturnSelf();

    DB::shouldReceive('get')
        ->andReturn(collect([]));

    $response = getJson('/old-datasets/100/dates');

    $response->assertStatus(200)
        ->assertJson([
            'dates' => [],
        ]);
});
