<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\ListingCountService;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

covers(ListingCountService::class);

beforeEach(function (): void {
    Cache::flush();
});

it('creates stable fingerprints from count-relevant filters only', function (): void {
    $service = app(ListingCountService::class);

    $first = $service->fingerprint([
        'page' => 4,
        'per_page' => 10,
        'sort' => 'title',
        'direction' => 'asc',
        'type' => ['software', 'dataset'],
        'temporal' => ['yearTo' => 2025, 'yearFrom' => 2020],
    ]);
    $second = $service->fingerprint([
        'temporal' => ['yearFrom' => 2020, 'yearTo' => 2025],
        'type' => ['dataset', 'software'],
        'direction' => 'desc',
        'sort' => 'updated_at',
        'page' => 1,
        'per_page' => 100,
    ]);

    expect($first)->toBe($second)
        ->and($service->fingerprint(['type' => ['dataset']]))->not->toBe($first);
});

it('reuses a cached exact count for the same fingerprint', function (): void {
    $service = app(ListingCountService::class);
    $resolverCalls = 0;
    $resolver = function () use (&$resolverCalls): int {
        $resolverCalls++;

        return 42;
    };

    $first = $service->remember(CacheKey::RESOURCE_LISTING_COUNT, ['search' => 'climate'], $resolver);
    $second = $service->remember(CacheKey::RESOURCE_LISTING_COUNT, ['search' => 'climate'], $resolver);

    expect($first)->toBe(42)
        ->and($second)->toBe(42)
        ->and($resolverCalls)->toBe(1);
});

it('uses a count cached by the lock holder when its own lock wait times out', function (): void {
    $service = app(ListingCountService::class);
    $criteria = ['search' => 'climate'];
    $cacheKey = CacheKey::RESOURCE_LISTING_COUNT->key($service->fingerprint($criteria));
    $resolverCalls = 0;
    $lock = Mockery::mock();
    $originalCacheManager = Cache::getFacadeRoot();
    $cacheManager = Mockery::mock(CacheManager::class, [app()])->makePartial();
    $repository = method_exists($cacheManager->getStore(), 'tags')
        ? $cacheManager->tags(CacheKey::RESOURCE_LISTING_COUNT->tags())
        : $cacheManager->store();

    $lock->shouldReceive('block')
        ->once()
        ->andReturnUsing(function () use ($repository, $cacheKey): never {
            $repository->put($cacheKey, 73, 60);

            throw new LockTimeoutException;
        });
    $cacheManager->shouldReceive('lock')
        ->once()
        ->andReturn($lock);
    Cache::swap($cacheManager);

    try {
        $count = $service->remember(CacheKey::RESOURCE_LISTING_COUNT, $criteria, function () use (&$resolverCalls): int {
            $resolverCalls++;

            return 99;
        });
    } finally {
        Cache::swap($originalCacheManager);
    }

    expect($count)->toBe(73)
        ->and($resolverCalls)->toBe(0);
});

it('propagates a lock timeout instead of starting an unlocked count', function (): void {
    $service = app(ListingCountService::class);
    $resolverCalls = 0;
    $lock = Mockery::mock();
    $originalCacheManager = Cache::getFacadeRoot();
    $cacheManager = Mockery::mock(CacheManager::class, [app()])->makePartial();

    $lock->shouldReceive('block')
        ->once()
        ->andThrow(new LockTimeoutException);
    $cacheManager->shouldReceive('lock')
        ->once()
        ->andReturn($lock);
    Cache::swap($cacheManager);

    try {
        expect(fn (): int => $service->remember(
            CacheKey::RESOURCE_LISTING_COUNT,
            ['search' => 'climate'],
            function () use (&$resolverCalls): int {
                $resolverCalls++;

                return 99;
            },
        ))->toThrow(LockTimeoutException::class)
            ->and($resolverCalls)->toBe(0);
    } finally {
        Cache::swap($originalCacheManager);
    }
});
