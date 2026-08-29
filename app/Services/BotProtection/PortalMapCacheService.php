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
