<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Resource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing resource-related caching.
 *
 * This service handles caching of resource listings and individual resources,
 * with automatic cache invalidation when resources are modified.
 */
class ResourceCacheService
{
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

        return Cache::tags(CacheKey::RESOURCE_LIST->tags())
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

        return Cache::tags(CacheKey::RESOURCE_DETAIL->tags())
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

        return Cache::tags(CacheKey::RESOURCE_COUNT->tags())
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
     * @return void
     */
    public function invalidateAllResourceCaches(): void
    {
        Cache::tags(['resources'])->flush();
    }

    /**
     * Invalidate cache for a specific resource.
     *
     * @param int $resourceId The resource ID
     * @return void
     */
    public function invalidateResourceCache(int $resourceId): void
    {
        $cacheKey = CacheKey::RESOURCE_DETAIL->key($resourceId);
        Cache::tags(CacheKey::RESOURCE_DETAIL->tags())->forget($cacheKey);

        // Also invalidate list caches since the resource might appear in listings
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
                sort($value);
                $normalized[] = "{$key}:" . implode(',', $value);
            } else {
                $normalized[] = "{$key}:{$value}";
            }
        }

        return empty($normalized) ? 'no_filters' : implode('|', $normalized);
    }
}
