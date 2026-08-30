<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\Resources\ResourceFilterOptionsCacheInvalidationService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;

it('defers resource filter option invalidation until the transaction commits', function (): void {
    Cache::tags(CacheKey::RESOURCE_FILTER_OPTIONS->tags())
        ->put(CacheKey::RESOURCE_FILTER_OPTIONS->key(), ['cached' => true], 300);

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

    $service = new ResourceFilterOptionsCacheInvalidationService($databaseManager);
    $service->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::RESOURCE_FILTER_OPTIONS->tags())->has(CacheKey::RESOURCE_FILTER_OPTIONS->key()))->toBeTrue()
        ->and($afterCommit)->toBeInstanceOf(Closure::class);

    $afterCommit();

    expect(Cache::tags(CacheKey::RESOURCE_FILTER_OPTIONS->tags())->has(CacheKey::RESOURCE_FILTER_OPTIONS->key()))->toBeFalse();
});

it('invalidates resource filter options immediately when no transaction is open', function (): void {
    Cache::tags(CacheKey::RESOURCE_FILTER_OPTIONS->tags())
        ->put(CacheKey::RESOURCE_FILTER_OPTIONS->key(), ['cached' => true], 300);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andReturn($connection);

    (new ResourceFilterOptionsCacheInvalidationService($databaseManager))->scheduleAfterCommit();

    expect(Cache::tags(CacheKey::RESOURCE_FILTER_OPTIONS->tags())->has(CacheKey::RESOURCE_FILTER_OPTIONS->key()))->toBeFalse();
});
