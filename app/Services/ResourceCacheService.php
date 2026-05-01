<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing resource-related caching.
 *
 * This service handles caching of resource listings and individual resources,
 * with automatic cache invalidation when resources are modified.
 */
class ResourceCacheService
{
    use ChecksCacheTagging;

    private const PHYSICAL_OBJECT_TYPE_ID_CACHE_SUFFIX = 'physical_object_type_id';

    private const DATA_RESOURCE_COUNT_CACHE_SUFFIX = 'data_resources';

    private const IGSN_RESOURCE_COUNT_CACHE_SUFFIX = 'igsn_resources';

    /**
     * Cache a paginated resource listing.
     *
     * @param  Builder<Resource>  $query  The base query builder
     * @param  int  $perPage  Items per page
     * @param  int  $currentPage  Current page number
     * @param  array<string, mixed>  $filters  Active filters
     * @return LengthAwarePaginator<int, Resource>
     */
    public function cacheResourceList(
        Builder $query,
        int $perPage,
        int $currentPage,
        array $filters = []
    ): LengthAwarePaginator {
        $cacheKey = $this->buildListCacheKey($perPage, $currentPage, $filters);

        return $this->getCacheInstance(CacheKey::RESOURCE_LIST->tags())
            ->remember(
                $cacheKey,
                CacheKey::RESOURCE_LIST->ttl(),
                fn () => $query->paginate($perPage, ['*'], 'page', $currentPage)
            );
    }

    /**
     * Cache an individual resource with its relationships.
     *
     * @param  int  $resourceId  The resource ID
     * @param  \Closure(): ?Resource  $callback  Callback to load the resource
     */
    public function cacheResource(int $resourceId, \Closure $callback): ?Resource
    {
        $cacheKey = CacheKey::RESOURCE_DETAIL->key($resourceId);

        return $this->getCacheInstance(CacheKey::RESOURCE_DETAIL->tags())
            ->remember(
                $cacheKey,
                CacheKey::RESOURCE_DETAIL->ttl(),
                $callback
            );
    }

    /**
     * Get cached resource count.
     *
     * @param  \Closure(): int  $callback  Callback to count resources
     */
    public function cacheResourceCount(\Closure $callback, string|int|null $suffix = null): int
    {
        $cacheKey = CacheKey::RESOURCE_COUNT->key($suffix);

        return (int) $this->getCacheInstance(CacheKey::RESOURCE_COUNT->tags())
            ->remember(
                $cacheKey,
                CacheKey::RESOURCE_COUNT->ttl(),
                $callback
            );
    }

    /**
     * Get the resource type ID for physical objects.
     */
    public function getPhysicalObjectTypeId(): ?int
    {
        $cacheKey = CacheKey::RESOURCE_COUNT->key(self::PHYSICAL_OBJECT_TYPE_ID_CACHE_SUFFIX);
        $cache = $this->getCacheInstance(CacheKey::RESOURCE_COUNT->tags());
        $cachedTypeId = $cache->get($cacheKey);

        if ($cachedTypeId !== null) {
            return (int) $cachedTypeId;
        }

        $physicalObjectTypeId = ResourceType::query()
            ->where('slug', 'physical-object')
            ->value('id');

        if ($physicalObjectTypeId === null) {
            return null;
        }

        $cache->put($cacheKey, $physicalObjectTypeId, CacheKey::RESOURCE_COUNT->ttl());

        return (int) $physicalObjectTypeId;
    }

    /**
     * Get the total count of non-IGSN resources.
     */
    public function getDataResourceCount(?int $physicalObjectTypeId): int
    {
        return $this->cacheResourceCount(
            callback: function () use ($physicalObjectTypeId): int {
                if ($physicalObjectTypeId === null) {
                    return Resource::query()->count();
                }

                return Resource::query()
                    ->where(function (Builder $query) use ($physicalObjectTypeId): void {
                        $query->whereNull('resource_type_id')
                            ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
                    })
                    ->count();
            },
            suffix: $this->buildCountCacheSuffix(self::DATA_RESOURCE_COUNT_CACHE_SUFFIX, $physicalObjectTypeId),
        );
    }

    /**
     * Get the total count of IGSN resources.
     */
    public function getIgsnCount(?int $physicalObjectTypeId): int
    {
        if ($physicalObjectTypeId === null) {
            return 0;
        }

        return $this->cacheResourceCount(
            callback: fn (): int => Resource::query()
                ->where('resource_type_id', $physicalObjectTypeId)
                ->count(),
            suffix: $this->buildCountCacheSuffix(self::IGSN_RESOURCE_COUNT_CACHE_SUFFIX, $physicalObjectTypeId),
        );
    }

    /**
     * Invalidate all resource-related caches.
     *
     * This should be called when any resource is created, updated, or deleted.
     *
     * WARNING: When cache tagging is not supported (e.g., file/database drivers),
     * this will call Cache::flush() which clears the ENTIRE cache store,
     * including sessions, vocabularies, ROR data, and any other cached data.
     */
    public function invalidateAllResourceCaches(): void
    {
        if ($this->supportsTagging()) {
            Cache::tags(['resources'])->flush();
        } else {
            // Log warning before clearing entire cache store
            Log::warning('Cache tagging not supported. Clearing entire cache store for resource invalidation.');
            Cache::flush();
        }
    }

    /**
     * Invalidate cache for a specific resource.
     *
     * This invalidates all resource caches since the resource might appear in listings.
     * There's no need to forget the individual cache entry first since
     * invalidateAllResourceCaches() will flush all resource-related caches.
     *
     * @param  int  $resourceId  The resource ID
     */
    public function invalidateResourceCache(int $resourceId): void
    {
        // Invalidate all resource caches (lists and detail pages)
        $this->invalidateAllResourceCaches();
    }

    /**
     * Build a cache key for a resource list request.
     *
     * @param  int  $perPage  Items per page
     * @param  int  $currentPage  Current page number
     * @param  array<string, mixed>  $filters  Active filters
     */
    private function buildListCacheKey(int $perPage, int $currentPage, array $filters): string
    {
        $filterString = $this->normalizeFilters($filters);

        return CacheKey::RESOURCE_LIST->key(
            implode(':', [$perPage, $currentPage, $filterString])
        );
    }

    private function buildCountCacheSuffix(string $prefix, ?int $physicalObjectTypeId): string
    {
        return $physicalObjectTypeId === null
            ? "{$prefix}:none"
            : "{$prefix}:{$physicalObjectTypeId}";
    }

    /**
     * Normalize filters into a consistent string representation.
     *
     * @param  array<string, mixed>  $filters
     */
    private function normalizeFilters(array $filters): string
    {
        if (empty($filters)) {
            return 'no_filters';
        }

        // Sort filters by key for consistent cache keys
        ksort($filters);

        $normalized = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                // Validate array contains only scalar values
                foreach ($value as $item) {
                    if (! is_scalar($item)) {
                        throw new \InvalidArgumentException('Filter array values must be scalar types');
                    }
                }

                // Convert all values to strings first, then sort with SORT_STRING for consistency
                $stringValues = array_map('strval', $value);
                sort($stringValues, SORT_STRING);

                // Escape delimiter characters to prevent cache key collisions
                $escapedValues = array_map(fn ($v) => str_replace([':', '|', ','], ['\\:', '\\|', '\\,'], $v), $stringValues);
                $normalized[] = "{$key}:".implode(',', $escapedValues);
            } else {
                if (! is_scalar($value)) {
                    throw new \InvalidArgumentException('Filter values must be scalar types');
                }

                // Escape delimiter characters
                $escapedValue = str_replace([':', '|'], ['\\:', '\\|'], (string) $value);
                $normalized[] = "{$key}:{$escapedValue}";
            }
        }

        return empty($normalized) ? 'no_filters' : implode('|', $normalized);
    }
}
