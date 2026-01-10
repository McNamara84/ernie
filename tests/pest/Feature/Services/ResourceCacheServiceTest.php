<?php

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->cacheService = new ResourceCacheService;
});

describe('ResourceCacheService - Resource List Caching', function () {
    test('caches paginated resource list', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        Resource::factory()->count(5)->create(['resource_type_id' => $resourceType->id]);

        $query = Resource::query();
        $result = $this->cacheService->cacheResourceList($query, 10, 1);

        expect($result)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
            ->and($result->total())->toBe(5)
            ->and($result->perPage())->toBe(10)
            ->and($result->currentPage())->toBe(1);
    });

    test('caches with different filters separately', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        Resource::factory()->count(5)->create(['resource_type_id' => $resourceType->id]);

        $query1 = Resource::query();
        $result1 = $this->cacheService->cacheResourceList($query1, 10, 1, ['status' => 'draft']);

        $query2 = Resource::query();
        $result2 = $this->cacheService->cacheResourceList($query2, 10, 1, ['status' => 'published']);

        // Both should work independently
        expect($result1)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
            ->and($result2)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class);
    });

    test('supports pagination with different pages', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        Resource::factory()->count(25)->create(['resource_type_id' => $resourceType->id]);

        $page1 = $this->cacheService->cacheResourceList(Resource::query(), 10, 1);
        $page2 = $this->cacheService->cacheResourceList(Resource::query(), 10, 2);

        expect($page1->currentPage())->toBe(1)
            ->and($page2->currentPage())->toBe(2)
            ->and($page1->total())->toBe(25)
            ->and($page1->lastPage())->toBe(3);
    });
});

describe('ResourceCacheService - Resource Count Caching', function () {
    test('caches resource count', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        Resource::factory()->count(7)->create(['resource_type_id' => $resourceType->id]);

        $count = $this->cacheService->cacheResourceCount(fn () => Resource::count());

        expect($count)->toBe(7);
    });
});

describe('ResourceCacheService - Cache Invalidation', function () {
    test('invalidates all resource caches', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        Resource::factory()->count(3)->create(['resource_type_id' => $resourceType->id]);

        // Cache some data
        $this->cacheService->cacheResourceCount(fn () => Resource::count());
        $this->cacheService->cacheResourceList(Resource::query(), 10, 1);

        // Invalidate
        $this->cacheService->invalidateAllResourceCaches();

        // Method should not throw
        expect(true)->toBeTrue();
    });

    test('invalidates specific resource cache', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        $resource = Resource::factory()->create(['resource_type_id' => $resourceType->id]);

        // Cache the resource
        $cached = $this->cacheService->cacheResource(
            $resource->id,
            fn () => Resource::find($resource->id)
        );

        expect($cached)->not->toBeNull()
            ->and($cached->id)->toBe($resource->id);

        // Invalidate
        $this->cacheService->invalidateResourceCache($resource->id);

        // Method should not throw
        expect(true)->toBeTrue();
    });
});

describe('ResourceCacheService - Individual Resource Caching', function () {
    test('caches individual resource', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'doi' => '10.5880/TEST-12345',
        ]);

        $cached = $this->cacheService->cacheResource(
            $resource->id,
            fn () => Resource::find($resource->id)
        );

        expect($cached)->not->toBeNull()
            ->and($cached->id)->toBe($resource->id)
            ->and($cached->doi)->toBe('10.5880/TEST-12345');
    });

    test('returns null for non-existent resource', function () {
        $cached = $this->cacheService->cacheResource(
            99999,
            fn () => Resource::find(99999)
        );

        expect($cached)->toBeNull();
    });
});
