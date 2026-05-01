<?php

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->cacheService = new ResourceCacheService;
    Cache::flush();
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

    test('caches data resource counts separately per excluded resource type id', function () {
        $typeA = ResourceType::create(['name' => 'Type A', 'slug' => 'type-a']);
        $typeB = ResourceType::create(['name' => 'Type B', 'slug' => 'type-b']);

        Resource::factory()->count(2)->create(['resource_type_id' => $typeA->id]);
        Resource::factory()->create(['resource_type_id' => $typeB->id]);
        Resource::factory()->create(['resource_type_id' => null]);

        $countExcludingTypeA = $this->cacheService->getDataResourceCount($typeA->id);
        $countExcludingTypeB = $this->cacheService->getDataResourceCount($typeB->id);

        expect($countExcludingTypeA)->toBe(2)
            ->and($countExcludingTypeB)->toBe(3);
    });

    test('caches IGSN counts separately per resource type id', function () {
        $typeA = ResourceType::create(['name' => 'Type A', 'slug' => 'type-a']);
        $typeB = ResourceType::create(['name' => 'Type B', 'slug' => 'type-b']);

        Resource::factory()->count(2)->create(['resource_type_id' => $typeA->id]);
        Resource::factory()->create(['resource_type_id' => $typeB->id]);

        $countForTypeA = $this->cacheService->getIgsnCount($typeA->id);
        $countForTypeB = $this->cacheService->getIgsnCount($typeB->id);

        expect($countForTypeA)->toBe(2)
            ->and($countForTypeB)->toBe(1);
    });

    test('caches the physical object type id after the first lookup', function () {
        $physicalObjectType = ResourceType::create(['name' => 'Physical Object', 'slug' => 'physical-object']);

        expect($this->cacheService->getPhysicalObjectTypeId())->toBe($physicalObjectType->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect($this->cacheService->getPhysicalObjectTypeId())->toBe($physicalObjectType->id)
            ->and(DB::getQueryLog())->toBe([]);

        DB::disableQueryLog();
    });

    test('invalidates the cached physical object type id when resource types change', function () {
        $physicalObjectType = ResourceType::create(['name' => 'Physical Object', 'slug' => 'physical-object']);

        expect($this->cacheService->getPhysicalObjectTypeId())->toBe($physicalObjectType->id);

        $physicalObjectType->update(['slug' => 'sample']);

        expect($this->cacheService->getPhysicalObjectTypeId())->toBeNull();

        $physicalObjectType->update(['slug' => 'physical-object']);

        expect($this->cacheService->getPhysicalObjectTypeId())->toBe($physicalObjectType->id);

        $physicalObjectType->delete();

        expect($this->cacheService->getPhysicalObjectTypeId())->toBeNull();
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
