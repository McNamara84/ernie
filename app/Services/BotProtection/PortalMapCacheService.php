<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Illuminate\Http\Request;

final class PortalMapCacheService
{
    use ChecksCacheTagging;

    /** @var list<string> */
    private const EXTENT_FILTER_KEYS = [
        'portal_scope',
        'query',
        'type',
        'exclude_type',
        'keywords',
        'free_keywords',
        'thesaurus_keywords',
        'datacenter',
        'bounds',
        'temporal',
    ];

    /**
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(Request $request, Closure $resolver): array
    {
        $cacheKey = CacheKey::PORTAL_MAP_PAYLOAD;

        if (! (bool) config('bot_protection.enabled', true) || $cacheKey->ttl() <= 0) {
            return $resolver();
        }

        return $this->getCacheInstance($cacheKey->tags())
            ->remember($this->keyForRequest($request), $cacheKey->ttl(), $resolver);
    }

    public function keyForRequest(Request $request): string
    {
        $query = $this->sortRecursively($request->query());
        $encodedQuery = json_encode($query);
        $fingerprint = is_string($encodedQuery) ? $encodedQuery : '';

        return CacheKey::PORTAL_MAP_PAYLOAD->key(hash('sha256', $request->path().'|'.$fingerprint));
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
    public function rememberExtent(array $filters, Closure $resolver): array
    {
        $cacheKey = CacheKey::PORTAL_MAP_EXTENT;

        if (! (bool) config('bot_protection.enabled', true) || $cacheKey->ttl() <= 0) {
            return $resolver();
        }

        return $this->getCacheInstance($cacheKey->tags())
            ->remember($this->extentKeyForFilters($filters), $cacheKey->ttl(), $resolver);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function extentKeyForFilters(array $filters): string
    {
        $semanticFilters = array_intersect_key($filters, array_flip(self::EXTENT_FILTER_KEYS));
        $encodedFilters = json_encode($this->sortRecursively($semanticFilters));
        $fingerprint = is_string($encodedFilters) ? $encodedFilters : '';

        return CacheKey::PORTAL_MAP_EXTENT->key(hash('sha256', $fingerprint));
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
}
