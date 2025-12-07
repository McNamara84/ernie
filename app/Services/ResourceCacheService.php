<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Resource;
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

    /**
     * Get cache instance with tags if supported, otherwise without tags.
     *
     * @param array<int, string> $tags
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function getCacheInstance(array $tags): \Illuminate\Contracts\Cache\Repository
    {
        if ($this->supportsTagging()) {
            return Cache::tags($tags);
        }

        return Cache::store();
    }

    /**
     * Cache a paginated resource listing.
     *
     * @param Builder<Resource> $query The base query builder
     * @param int $perPage Items per page
     * @param int $currentPage Current page number
     * @param array<string, mixed> $filters Active filters
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
     * @param int $resourceId The resource ID
     * @param \Closure(): ?Resource $callback Callback to load the resource
     * @return Resource|null
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
     * @param \Closure(): int $callback Callback to count resources
     * @return int
     */
    public function cacheResourceCount(\Closure $callback): int
    {
        $cacheKey = CacheKey::RESOURCE_COUNT->key();

        return $this->getCacheInstance(CacheKey::RESOURCE_COUNT->tags())
            ->remember(
                $cacheKey,
                CacheKey::RESOURCE_COUNT->ttl(),
                $callback
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
     *
     * @return void
     */
    public function invalidateAllResourceCaches(): void
    {
        if ($this->supportsTagging()) {
            Cache::tags(['resources'])->flush();
        } else {
            // Log warning before clearing entire cache store
            \Log::warning('Cache tagging not supported. Clearing entire cache store for resource invalidation.');
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
     * @param int $resourceId The resource ID
     * @return void
     */
    public function invalidateResourceCache(int $resourceId): void
    {
        // Invalidate all resource caches (lists and detail pages)
        $this->invalidateAllResourceCaches();
    }

    /**
     * Build a cache key for a resource list request.
     *
     * @param int $perPage Items per page
     * @param int $currentPage Current page number
     * @param array<string, mixed> $filters Active filters
     * @return string
     */
    private function buildListCacheKey(int $perPage, int $currentPage, array $filters): string
    {
        $filterString = $this->normalizeFilters($filters);

        return CacheKey::RESOURCE_LIST->key(
            implode(':', [$perPage, $currentPage, $filterString])
        );
    }

    /**
     * Normalize filters into a consistent string representation.
     *
     * @param array<string, mixed> $filters
     * @return string
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
                $escapedValues = array_map(fn($v) => str_replace([':', '|', ','], ['\\:', '\\|', '\\,'], $v), $stringValues);
                $normalized[] = "{$key}:" . implode(',', $escapedValues);
            } else {
                if (! is_scalar($value)) {
                    throw new \InvalidArgumentException('Filter values must be scalar types');
                }
                
                // Escape delimiter characters
                $escapedValue = str_replace([':', '|'], ['\\:', '\\|'], (string)$value);
                $normalized[] = "{$key}:{$escapedValue}";
            }
        }

        return empty($normalized) ? 'no_filters' : implode('|', $normalized);
    }
}
