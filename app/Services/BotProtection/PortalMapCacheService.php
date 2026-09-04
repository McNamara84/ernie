<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Services\FlexibleCacheService;
use App\Services\PortalCacheVersionService;
use App\Support\PortalCacheNamespace;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Illuminate\Http\Request;

final class PortalMapCacheService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly FlexibleCacheService $flexibleCache,
        private readonly PortalCacheVersionService $versionService,
    ) {}

    /** @var list<string> */
    private const EXTENT_FILTER_KEYS = [
        'portal_scope',
        'query',
        'type',
        'exclude_type',
        'keywords',
        'free_keywords',
        'thesaurus_keywords',
        'sample_types',
        'materials',
        'classifications',
        'geological_ages',
        'geological_units',
        'datacenter',
        'bounds',
        'temporal',
    ];

    /**
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(Request $request, Closure $resolver, ?PortalScope $scope = null): array
    {
        $cacheKey = CacheKey::PORTAL_MAP_PAYLOAD;

        if (! (bool) config('bot_protection.enabled', true) || $cacheKey->ttl() <= 0) {
            return $resolver();
        }

        $scope ??= $this->scopeForRequest($request);

        /** @var array<string, mixed> */
        return $this->flexibleCache->remember(
            $this->getCacheInstance(PortalCacheNamespace::tags($cacheKey, $scope)),
            $this->keyForRequest($request, $scope),
            intdiv($cacheKey->ttl(), 2),
            $cacheKey->ttl(),
            $resolver,
            (int) config('bot_protection.portal_cache_lock_seconds', 15),
            (int) config('bot_protection.portal_cache_lock_wait_seconds', 10),
        );
    }

    public function keyForRequest(Request $request, ?PortalScope $scope = null): string
    {
        $scope ??= $this->scopeForRequest($request);
        $query = $this->sortRecursively($request->query());
        $encodedQuery = json_encode($query);
        $fingerprint = is_string($encodedQuery) ? $encodedQuery : '';

        return CacheKey::PORTAL_MAP_PAYLOAD->key(implode(':', [
            $scope->value,
            'v'.$this->versionService->current(CacheKey::PORTAL_MAP_PAYLOAD, $scope),
            hash('sha256', $request->path().'|'.$fingerprint),
        ]));
    }

    /**
     * Cache the expensive total/extent scan independently from technical map
     * viewport coordinates and dimensions, so all clients using the same
     * semantic portal filters share it.
     *
     * @param  array<string, mixed>  $filters
     * @param  Closure(): array{0: int, 1: array{south: float, west: float, north: float, east: float}|null}  $resolver
     * @return array{0: int, 1: array{south: float, west: float, north: float, east: float}|null}
     */
    public function rememberExtent(array $filters, Closure $resolver, ?PortalScope $scope = null): array
    {
        $cacheKey = CacheKey::PORTAL_MAP_EXTENT;

        if (! (bool) config('bot_protection.enabled', true) || $cacheKey->ttl() <= 0) {
            return $resolver();
        }

        $scope ??= $this->scopeForFilters($filters);

        /** @var array{0: int, 1: array{south: float, west: float, north: float, east: float}|null} */
        return $this->flexibleCache->remember(
            $this->getCacheInstance(PortalCacheNamespace::tags($cacheKey, $scope)),
            $this->extentKeyForFilters($filters, $scope),
            intdiv($cacheKey->ttl(), 2),
            $cacheKey->ttl(),
            $resolver,
            (int) config('bot_protection.portal_cache_lock_seconds', 15),
            (int) config('bot_protection.portal_cache_lock_wait_seconds', 10),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function extentKeyForFilters(array $filters, ?PortalScope $scope = null): string
    {
        $scope ??= $this->scopeForFilters($filters);
        $semanticFilters = array_intersect_key($filters, array_flip(self::EXTENT_FILTER_KEYS));
        $encodedFilters = json_encode($this->sortRecursively($semanticFilters));
        $fingerprint = is_string($encodedFilters) ? $encodedFilters : '';

        return CacheKey::PORTAL_MAP_EXTENT->key(implode(':', [
            $scope->value,
            'v'.$this->versionService->current(CacheKey::PORTAL_MAP_EXTENT, $scope),
            hash('sha256', $fingerprint),
        ]));
    }

    public function flushPayload(?PortalScope $scope = null): void
    {
        $this->invalidate(CacheKey::PORTAL_MAP_PAYLOAD, $scope);
    }

    public function flushExtent(?PortalScope $scope = null): void
    {
        $this->invalidate(CacheKey::PORTAL_MAP_EXTENT, $scope);
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function sortRecursively(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortRecursively($value);
            }
        }

        if (array_is_list($values)) {
            sort($values);
        } else {
            ksort($values);
        }

        return $values;
    }

    private function invalidate(CacheKey $cacheKey, ?PortalScope $scope): void
    {
        foreach ($scope === null ? PortalScope::cases() : [$scope] as $portalScope) {
            $this->versionService->invalidate($cacheKey, $portalScope);
        }
    }

    private function scopeForRequest(Request $request): PortalScope
    {
        $routeScope = $request->route('portalScope');
        if (is_string($routeScope) && PortalScope::tryFrom($routeScope) instanceof PortalScope) {
            return PortalScope::from($routeScope);
        }

        return str_starts_with($request->path(), 'igsn-search')
            ? PortalScope::IGSN
            : PortalScope::DOI;
    }

    /** @param array<string, mixed> $filters */
    private function scopeForFilters(array $filters): PortalScope
    {
        $scope = $filters['portal_scope'] ?? null;

        return is_string($scope) && PortalScope::tryFrom($scope) instanceof PortalScope
            ? PortalScope::from($scope)
            : PortalScope::DOI;
    }
}
