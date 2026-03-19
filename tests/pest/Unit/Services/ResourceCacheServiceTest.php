<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;

covers(ResourceCacheService::class);

describe('ResourceCacheService', function () {
    beforeEach(function () {
        $this->service = new ResourceCacheService;
        Cache::flush();
    });

    describe('cacheResourceCount', function () {
        it('caches and returns the resource count', function () {
            $result = $this->service->cacheResourceCount(fn () => 42);

            expect($result)->toBe(42);
        });

        it('returns cached value on subsequent calls', function () {
            $callCount = 0;
            $callback = function () use (&$callCount) {
                $callCount++;

                return 10;
            };

            $this->service->cacheResourceCount($callback);
            $this->service->cacheResourceCount($callback);

            expect($callCount)->toBe(1);
        });
    });

    describe('cacheResource', function () {
        it('caches and returns a resource', function () {
            $resource = Resource::factory()->create();

            $cached = $this->service->cacheResource($resource->id, fn () => $resource);

            expect($cached)->toBeInstanceOf(Resource::class);
            expect($cached->id)->toBe($resource->id);
        });

        it('returns null when callback returns null', function () {
            $result = $this->service->cacheResource(999, fn () => null);

            expect($result)->toBeNull();
        });

        it('returns cached value on subsequent calls', function () {
            $resource = Resource::factory()->create();
            $callCount = 0;

            $callback = function () use ($resource, &$callCount) {
                $callCount++;

                return $resource;
            };

            $this->service->cacheResource($resource->id, $callback);
            $this->service->cacheResource($resource->id, $callback);

            expect($callCount)->toBe(1);
        });
    });

    describe('cacheResourceList', function () {
        it('returns paginated resources', function () {
            Resource::factory()->count(3)->create();

            $query = Resource::query();
            $result = $this->service->cacheResourceList($query, 10, 1);

            expect($result->total())->toBe(3);
        });
    });

    describe('invalidateAllResourceCaches', function () {
        it('flushes cache without errors', function () {
            // Seed cache first
            $this->service->cacheResourceCount(fn () => 5);

            $this->service->invalidateAllResourceCaches();

            // After invalidation, callback should be called again
            $callCount = 0;
            $this->service->cacheResourceCount(function () use (&$callCount) {
                $callCount++;

                return 10;
            });

            expect($callCount)->toBe(1);
        });
    });

    describe('invalidateResourceCache', function () {
        it('invalidates cache for a specific resource', function () {
            $resource = Resource::factory()->create();

            $this->service->cacheResource($resource->id, fn () => $resource);
            $this->service->invalidateResourceCache($resource->id);

            // After invalidation, callback should be called again
            $callCount = 0;
            $this->service->cacheResource($resource->id, function () use ($resource, &$callCount) {
                $callCount++;

                return $resource;
            });

            expect($callCount)->toBe(1);
        });
    });
});
