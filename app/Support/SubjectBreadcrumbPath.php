<?php

declare(strict_types=1);

namespace App\Support;

final class SubjectBreadcrumbPath
{
    public static function normalize(?string $path): ?string
    {
        return PortalSubjectNormalizer::normalizeControlledSubjectValue($path);
    }

    public static function preferredPath(?string $breadcrumbPath, ?string $fallbackValue): ?string
    {
        $normalizedBreadcrumbPath = self::normalize($breadcrumbPath);
        if ($normalizedBreadcrumbPath !== null) {
            return $normalizedBreadcrumbPath;
        }

        $normalizedFallbackValue = self::normalize($fallbackValue);

        return self::hasHierarchy($normalizedFallbackValue) ? $normalizedFallbackValue : null;
    }

    /**
     * @return array<int, string>
     */
    public static function segments(?string $path): array
    {
        $normalizedPath = self::normalize($path);
        if ($normalizedPath === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $normalizedPath)),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    public static function hasHierarchy(?string $path): bool
    {
        return count(self::segments($path)) > 1;
    }

    public static function leaf(?string $path, ?string $fallback = null): ?string
    {
        $segments = self::segments($path);
        if ($segments !== []) {
            return $segments[array_key_last($segments)];
        }

        $trimmedFallback = trim((string) $fallback);

        return $trimmedFallback !== '' ? $trimmedFallback : null;
    }
}