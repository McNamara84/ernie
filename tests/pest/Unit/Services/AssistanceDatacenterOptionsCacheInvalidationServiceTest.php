<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\Assistance\AssistanceDatacenterOptionsCacheInvalidationService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;

covers(AssistanceDatacenterOptionsCacheInvalidationService::class);

function cacheAssistanceDatacenterOptionsForInvalidationTest(): void
{
    $cacheKey = CacheKey::ASSISTANCE_DATACENTER_OPTIONS;
    $repository = method_exists(Cache::getStore(), 'tags')
        ? Cache::tags($cacheKey->tags())
        : Cache::store();

    $repository->put($cacheKey->key(), [['id' => 1, 'name' => 'Cached Datacenter']], 300);
}

function hasAssistanceDatacenterOptionsForInvalidationTest(): bool
{
    $cacheKey = CacheKey::ASSISTANCE_DATACENTER_OPTIONS;
    $repository = method_exists(Cache::getStore(), 'tags')
        ? Cache::tags($cacheKey->tags())
        : Cache::store();

    return $repository->has($cacheKey->key());
}

it('coalesces invalidations until the transaction commits', function (): void {
    cacheAssistanceDatacenterOptionsForInvalidationTest();
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

    $service = new AssistanceDatacenterOptionsCacheInvalidationService($databaseManager);
    $service->scheduleAfterCommit();
    $service->scheduleAfterCommit();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeTrue()
        ->and($afterCommit)->toBeInstanceOf(Closure::class);

    $afterCommit();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeFalse();
});

it('keeps cached options on rollback and permits the next invalidation', function (): void {
    cacheAssistanceDatacenterOptionsForInvalidationTest();
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

    $service = new AssistanceDatacenterOptionsCacheInvalidationService($databaseManager);
    $service->scheduleAfterCommit();

    expect($afterRollback)->toBeInstanceOf(Closure::class);
    $afterRollback();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeTrue();

    $service->scheduleAfterCommit();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeFalse();
});

it('invalidates immediately outside a transaction', function (): void {
    cacheAssistanceDatacenterOptionsForInvalidationTest();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andReturn($connection);

    (new AssistanceDatacenterOptionsCacheInvalidationService($databaseManager))->scheduleAfterCommit();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeFalse();
});

it('falls back to immediate invalidation when the database connection is unavailable', function (): void {
    cacheAssistanceDatacenterOptionsForInvalidationTest();

    $databaseManager = Mockery::mock(DatabaseManager::class);
    $databaseManager->shouldReceive('connection')->once()->andThrow(new RuntimeException('Database unavailable'));

    (new AssistanceDatacenterOptionsCacheInvalidationService($databaseManager))->scheduleAfterCommit();

    expect(hasAssistanceDatacenterOptionsForInvalidationTest())->toBeFalse();
});
