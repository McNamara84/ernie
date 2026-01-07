<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Services\ResourceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear all caches before each test
    Cache::flush();

    $this->cacheService = app(ResourceCacheService::class);
});

it('caches resource list correctly', function () {
    // Create test resources
    $resources = Resource::factory()->count(3)->create();

    // Build a simple query
    $query = Resource::query()->with(['resourceType', 'language', 'titles']);

    // Cache the resource list
    $result = $this->cacheService->cacheResourceList($query, 10, 1, []);

    expect($result)->toHaveCount(3);

    // Verify cache was created
    $cacheKey = CacheKey::RESOURCE_LIST->key('10:1:no_filters');
    expect(Cache::tags(['resources'])->has($cacheKey))->toBeTrue();
});

it('invalidates resource cache on update', function () {
    $resource = Resource::factory()->create();

    // Cache the resource
    $cacheKey = CacheKey::RESOURCE_DETAIL->key($resource->id);
    Cache::tags(['resources'])->put($cacheKey, $resource, 3600);

    expect(Cache::tags(['resources'])->has($cacheKey))->toBeTrue();

    // Invalidate cache
    $this->cacheService->invalidateResourceCache($resource->id);

    expect(Cache::tags(['resources'])->has($cacheKey))->toBeFalse();
});

it('invalidates all resource caches', function () {
    // Create multiple cache entries
    $keys = ['key1', 'key2', 'key3'];
    foreach ($keys as $key) {
        Cache::tags(['resources'])->put($key, 'value', 3600);
    }

    // Verify all exist
    foreach ($keys as $key) {
        expect(Cache::tags(['resources'])->has($key))->toBeTrue();
    }

    // Invalidate all
    $this->cacheService->invalidateAllResourceCaches();

    // Verify all cleared
    foreach ($keys as $key) {
        expect(Cache::tags(['resources'])->has($key))->toBeFalse();
    }
});

it('builds cache keys with filters correctly', function () {
    $query = Resource::query()->with(['resourceType', 'language', 'titles']);

    $filters = [
        'resource_type' => ['dataset'],
        'search' => 'test',
    ];

    // Cache with filters
    $this->cacheService->cacheResourceList($query, 20, 2, $filters);

    // The cache key should include filter information
    $expectedKey = CacheKey::RESOURCE_LIST->key('20:2:resource_type:dataset|search:test');
    expect(Cache::tags(['resources'])->has($expectedKey))->toBeTrue();
});

it('caches resource count correctly', function () {
    Resource::factory()->count(5)->create();

    $count = $this->cacheService->cacheResourceCount(
        fn () => Resource::count()
    );

    expect($count)->toBe(5);

    // Verify cache was created
    $cacheKey = CacheKey::RESOURCE_COUNT->key();
    expect(Cache::tags(['resources'])->has($cacheKey))->toBeTrue();
});

it('observer invalidates cache on resource creation', function () {
    // Create a cache entry
    $cacheKey = 'test-resource-key';
    Cache::tags(['resources'])->put($cacheKey, 'test-value', 3600);

    expect(Cache::tags(['resources'])->has($cacheKey))->toBeTrue();

    // Create a new resource (should trigger observer)
    Resource::factory()->create();

    // Cache should be invalidated
    expect(Cache::tags(['resources'])->has($cacheKey))->toBeFalse();
});

it('observer invalidates cache on resource update', function () {
    $resource = Resource::factory()->create();

    // Create cache entries - both specific and general
    $specificKey = CacheKey::RESOURCE_DETAIL->key($resource->id);
    $listKey = 'test-list-cache-key';

    Cache::tags(['resources'])->put($specificKey, $resource, 3600);
    Cache::tags(['resources'])->put($listKey, 'test-value', 3600);

    expect(Cache::tags(['resources'])->has($specificKey))->toBeTrue();
    expect(Cache::tags(['resources'])->has($listKey))->toBeTrue();

    // Update the resource (should trigger observer)
    $resource->update(['publication_year' => 2025]);

    // All resource caches should be invalidated (because invalidateResourceCache calls invalidateAllResourceCaches)
    expect(Cache::tags(['resources'])->has($specificKey))->toBeFalse();
    expect(Cache::tags(['resources'])->has($listKey))->toBeFalse();
});

it('observer invalidates cache on resource deletion', function () {
    $resource = Resource::factory()->create();
    $resourceId = $resource->id;

    // Create a cache entry for this specific resource
    $cacheKey = CacheKey::RESOURCE_DETAIL->key($resourceId);
    Cache::tags(['resources'])->put($cacheKey, $resource, 3600);

    expect(Cache::tags(['resources'])->has($cacheKey))->toBeTrue();

    // Delete the resource (should trigger observer)
    $resource->delete();

    // Cache should be invalidated
    expect(Cache::tags(['resources'])->has($cacheKey))->toBeFalse();
});
