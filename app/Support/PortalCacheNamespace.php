<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CacheKey;
use App\Enums\PortalScope;

final class PortalCacheNamespace
{
    private const VERSION = 'v2';

    /** @return list<string> */
    public static function tags(CacheKey $cacheKey, ?PortalScope $scope): array
    {
        return [self::scopeTag($cacheKey, $scope)];
    }

    public static function scopeTag(CacheKey $cacheKey, ?PortalScope $scope): string
    {
        return implode(':', [
            'portal-cache',
            self::VERSION,
            $cacheKey->value,
            self::scopeValue($scope),
        ]);
    }

    public static function versionKey(CacheKey $cacheKey, ?PortalScope $scope): string
    {
        return implode(':', [
            'portal-cache-version',
            self::VERSION,
            $cacheKey->value,
            self::scopeValue($scope),
        ]);
    }

    public static function versionedKey(
        CacheKey $cacheKey,
        ?PortalScope $scope,
        int $version,
        string|int|null $suffix = null,
    ): string {
        $parts = [self::scopeValue($scope), 'v'.max(1, $version)];

        if ($suffix !== null && $suffix !== '') {
            $parts[] = (string) $suffix;
        }

        return $cacheKey->key(implode(':', $parts));
    }

    private static function scopeValue(?PortalScope $scope): string
    {
        return $scope instanceof PortalScope ? $scope->value : 'all';
    }
}
