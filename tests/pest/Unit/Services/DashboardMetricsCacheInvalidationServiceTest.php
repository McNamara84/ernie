<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Affiliation;
use App\Observers\DashboardMetricsObserver;
use App\Services\DashboardMetricsCacheInvalidationService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;

covers(DashboardMetricsCacheInvalidationService::class, DashboardMetricsObserver::class);

function cacheDashboardMetrics(): void
{
    Cache::tags(CacheKey::DASHBOARD_METRICS->tags())
        ->put(CacheKey::DASHBOARD_METRICS->key(), ['dataResourceCount' => 1], 300);
}

it('coalesces dashboard metric invalidations until the transaction commits', function (): void {
    cacheDashboardMetrics();
    $afterCommit = null;

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldReceive('afterCommit')->once()->with(Mockery::on(
        function (Closure $callback) use (&$afterCommit): bool {
            $afterCommit = $callback;

            return true;
        },
    ));
    $connection->shouldReceive('afterRollBack')->once()->with(Mockery::type(Closure::class));

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andReturn($connection);

    $service = new DashboardMetricsCacheInvalidationService($databaseManager);
    $service->scheduleAfterCommit();
    $service->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeTrue()
        ->and($afterCommit)->toBeInstanceOf(Closure::class);

    $afterCommit();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();
});

it('keeps cached metrics on rollback and permits the next invalidation', function (): void {
    cacheDashboardMetrics();
    $afterRollback = null;

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->twice()->andReturn(1, 0);
    $connection->shouldReceive('afterCommit')->once()->with(Mockery::type(Closure::class));
    $connection->shouldReceive('afterRollBack')->once()->with(Mockery::on(
        function (Closure $callback) use (&$afterRollback): bool {
            $afterRollback = $callback;

            return true;
        },
    ));

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->twice()->andReturn($connection);

    $service = new DashboardMetricsCacheInvalidationService($databaseManager);
    $service->scheduleAfterCommit();

    expect($afterRollback)->toBeInstanceOf(Closure::class);
    $afterRollback();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeTrue();

    $service->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();
});

it('invalidates dashboard metrics immediately outside a transaction', function (): void {
    cacheDashboardMetrics();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andReturn($connection);

    (new DashboardMetricsCacheInvalidationService($databaseManager))->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();
});

it('falls back to immediate invalidation when the database connection is unavailable', function (): void {
    cacheDashboardMetrics();

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andThrow(new RuntimeException('Database unavailable'));

    (new DashboardMetricsCacheInvalidationService($databaseManager))->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();
});

it('routes saved and deleted affiliation events through the transaction-aware invalidator', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->twice()->andReturn(0);

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->twice()->andReturn($connection);

    $observer = new DashboardMetricsObserver(new DashboardMetricsCacheInvalidationService($databaseManager));
    $affiliation = new Affiliation;

    cacheDashboardMetrics();
    $observer->saved($affiliation);
    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();

    cacheDashboardMetrics();
    $observer->deleted($affiliation);
    expect(Cache::tags(CacheKey::DASHBOARD_METRICS->tags())->has(CacheKey::DASHBOARD_METRICS->key()))->toBeFalse();
});
