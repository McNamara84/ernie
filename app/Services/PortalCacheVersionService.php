<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Support\PortalCacheNamespace;
use Illuminate\Support\Facades\Cache;

final class PortalCacheVersionService
{
    public function current(CacheKey $cacheKey, PortalScope $scope): int
    {
        return (int) Cache::rememberForever(
            PortalCacheNamespace::versionKey($cacheKey, $scope),
            static fn (): int => 1,
        );
    }

    public function invalidate(CacheKey $cacheKey, PortalScope $scope): int
    {
        $versionKey = PortalCacheNamespace::versionKey($cacheKey, $scope);
        Cache::add($versionKey, 1);
        $version = (int) Cache::increment($versionKey);

        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags(PortalCacheNamespace::tags($cacheKey, $scope))->flush();
        }

        return $version;
    }
}
