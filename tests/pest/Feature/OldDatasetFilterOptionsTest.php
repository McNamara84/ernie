<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

function mockOldDatasetFilterOptionsConnection(?int $yearMin, ?int $yearMax): void
{
    $resourceTypesQuery = Mockery::mock(Builder::class);
    $resourceTypesQuery->shouldReceive('distinct')->once()->andReturnSelf();
    $resourceTypesQuery->shouldReceive('whereNotNull')->once()->with('resourcetypegeneral')->andReturnSelf();
    $resourceTypesQuery->shouldReceive('where')->once()->with('resourcetypegeneral', '!=', '')->andReturnSelf();
    $resourceTypesQuery->shouldReceive('pluck')->once()->with('resourcetypegeneral')->andReturn(collect(['Dataset']));

    $curatorsQuery = Mockery::mock(Builder::class);
    $curatorsQuery->shouldReceive('distinct')->once()->andReturnSelf();
    $curatorsQuery->shouldReceive('whereNotNull')->once()->with('curator')->andReturnSelf();
    $curatorsQuery->shouldReceive('where')->once()->with('curator', '!=', '')->andReturnSelf();
    $curatorsQuery->shouldReceive('pluck')->once()->with('curator')->andReturn(collect(['Alice']));

    $yearMinQuery = Mockery::mock(Builder::class);
    $yearMinQuery->shouldReceive('whereNotNull')->once()->with('publicationyear')->andReturnSelf();
    $yearMinQuery->shouldReceive('where')->once()->with('publicationyear', '>', 0)->andReturnSelf();
    $yearMinQuery->shouldReceive('min')->once()->with('publicationyear')->andReturn($yearMin);

    $yearMaxQuery = Mockery::mock(Builder::class);
    $yearMaxQuery->shouldReceive('whereNotNull')->once()->with('publicationyear')->andReturnSelf();
    $yearMaxQuery->shouldReceive('where')->once()->with('publicationyear', '>', 0)->andReturnSelf();
    $yearMaxQuery->shouldReceive('max')->once()->with('publicationyear')->andReturn($yearMax);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('table')
        ->times(4)
        ->with('resource')
        ->andReturn($resourceTypesQuery, $curatorsQuery, $yearMinQuery, $yearMaxQuery);
    $connection->shouldReceive('raw')
        ->andReturnUsing(fn (string $value): Expression => new Expression($value));

    $realManager = DB::getFacadeRoot();
    test()->originalDbManager = $realManager;

    $mock = Mockery::mock($realManager)->makePartial();
    $mock->shouldReceive('connection')->with('metaworks')->andReturn($connection);

    DB::swap($mock);
}

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
});

afterEach(function (): void {
    if (isset(test()->originalDbManager)) {
        DB::swap(test()->originalDbManager);
    }
});

it('falls back to the current year when legacy datasets have no publication year range', function (): void {
    mockOldDatasetFilterOptionsConnection(yearMin: null, yearMax: null);

    $currentYear = (int) now()->year;

    $this->actingAs($this->admin)
        ->get('/old-datasets/filter-options')
        ->assertOk()
        ->assertJson([
            'resource_types' => ['Dataset'],
            'curators' => ['Alice'],
            'year_range' => [
                'min' => $currentYear,
                'max' => $currentYear,
            ],
            'statuses' => ['pending', 'released'],
        ]);
});

it('returns only positive publication year bounds for legacy datasets', function (): void {
    mockOldDatasetFilterOptionsConnection(yearMin: 2001, yearMax: 2024);

    $this->actingAs($this->admin)
        ->get('/old-datasets/filter-options')
        ->assertOk()
        ->assertJson([
            'resource_types' => ['Dataset'],
            'curators' => ['Alice'],
            'year_range' => [
                'min' => 2001,
                'max' => 2024,
            ],
            'statuses' => ['pending', 'released'],
        ]);
});