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

class PortalPageCacheService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly FlexibleCacheService $flexibleCache,
        private readonly PortalCacheVersionService $versionService,
    ) {}

    /**
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(Request $request, Closure $resolver, ?PortalScope $scope = null): array
    {
        if (! $this->shouldCache()) {
            return $resolver();
        }

        $cacheKey = CacheKey::PORTAL_PAGE_PAYLOAD;
        $scope ??= $this->scopeForRequest($request);

        /** @var array<string, mixed> */
        return $this->flexibleCache->remember(
            $this->getCacheInstance(PortalCacheNamespace::tags($cacheKey, $scope)),
            $this->keyForRequest($request, $scope),
            (int) config('bot_protection.portal_cache_fresh_ttl', 60),
            $cacheKey->ttl(),
            $resolver,
            (int) config('bot_protection.portal_cache_lock_seconds', 15),
            (int) config('bot_protection.portal_cache_lock_wait_seconds', 10),
        );
    }

    public function keyForRequest(Request $request, ?PortalScope $scope = null): string
    {
        $scope ??= $this->scopeForRequest($request);
        $query = $request->query();
        ksort($query);

        $encodedQuery = json_encode($query);
        $queryFingerprint = is_string($encodedQuery) ? $encodedQuery : '';

        return CacheKey::PORTAL_PAGE_PAYLOAD->key(implode(':', [
            $scope->value,
            'v'.$this->versionService->current(CacheKey::PORTAL_PAGE_PAYLOAD, $scope),
            hash('sha256', $request->path().'|'.$queryFingerprint),
        ]));
    }

    public function flush(?PortalScope $scope = null): void
    {
        $scopes = $scope === null ? PortalScope::cases() : [$scope];

        foreach ($scopes as $portalScope) {
            $this->versionService->invalidate(CacheKey::PORTAL_PAGE_PAYLOAD, $portalScope);
        }
    }

    private function shouldCache(): bool
    {
        return (bool) config('bot_protection.enabled', true)
            && CacheKey::PORTAL_PAGE_PAYLOAD->ttl() > 0;
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
}
