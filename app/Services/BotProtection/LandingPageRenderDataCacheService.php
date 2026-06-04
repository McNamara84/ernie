<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Illuminate\Support\Facades\Cache;

class LandingPageRenderDataCacheService
{
    use ChecksCacheTagging;

    /**
     * @param  Closure(): array{template: string, props: array<string, mixed>}  $resolver
     * @return array{template: string, props: array<string, mixed>}
     */
    public function remember(LandingPage $landingPage, Closure $resolver): array
    {
        if (! $this->shouldCache($landingPage)) {
            return $resolver();
        }

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA;

        /** @var array{template: string, props: array<string, mixed>} */
        return $this->getCacheInstance($cacheKey->tags())
            ->remember($cacheKey->key($landingPage->id), $cacheKey->ttl(), $resolver);
    }

    public function forget(LandingPage $landingPage): bool
    {
        return CacheKey::LANDING_PAGE_RENDER_DATA->forget($landingPage->id);
    }

    public function flush(): void
    {
        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA;

        if ($this->supportsTagging()) {
            Cache::tags($cacheKey->tags())->flush();

            return;
        }

        Cache::flush();
    }

    private function shouldCache(LandingPage $landingPage): bool
    {
        return (bool) config('bot_protection.enabled', true)
            && CacheKey::LANDING_PAGE_RENDER_DATA->ttl() > 0
            && $landingPage->isPublished();
    }
}
