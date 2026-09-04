<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CacheKey;
use App\Enums\PortalScope;

final class PortalCacheNamespace
{
    private const VERSION = 'v2';

    /** @return list<string> */
    public static function tags(CacheKey $cacheKey, PortalScope $scope): array
    {
        return [self::scopeTag($cacheKey, $scope)];
    }

    public static function scopeTag(CacheKey $cacheKey, PortalScope $scope): string
    {
        return implode(':', [
            'portal-cache',
            self::VERSION,
            $cacheKey->value,
            $scope->value,
        ]);
    }

    public static function versionKey(CacheKey $cacheKey, PortalScope $scope): string
    {
        return implode(':', [
            'portal-cache-version',
            self::VERSION,
            $cacheKey->value,
            $scope->value,
        ]);
    }
}
