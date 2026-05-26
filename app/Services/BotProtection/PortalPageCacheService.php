<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PortalPageCacheService
{
    use ChecksCacheTagging;

    /**
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(Request $request, Closure $resolver): array
    {
        if (! $this->shouldCache()) {
            return $resolver();
        }

        $cacheKey = CacheKey::PORTAL_PAGE_PAYLOAD;

        /** @var array<string, mixed> */
        return $this->getCacheInstance($cacheKey->tags())
            ->remember($this->keyForRequest($request), $cacheKey->ttl(), $resolver);
    }

    public function keyForRequest(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        $encodedQuery = json_encode($query);
        $queryFingerprint = is_string($encodedQuery) ? $encodedQuery : '';

        return CacheKey::PORTAL_PAGE_PAYLOAD->key(hash('sha256', $request->path().'|'.$queryFingerprint));
    }

    public function flush(): void
    {
        $cacheKey = CacheKey::PORTAL_PAGE_PAYLOAD;

        if ($this->supportsTagging()) {
            Cache::tags($cacheKey->tags())->flush();
        }
    }

    private function shouldCache(): bool
    {
        return (bool) config('bot_protection.enabled', true)
            && CacheKey::PORTAL_PAGE_PAYLOAD->ttl() > 0;
    }
}
