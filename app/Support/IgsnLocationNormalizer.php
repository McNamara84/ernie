<?php

declare(strict_types=1);

namespace App\Support;

final class IgsnLocationNormalizer
{
    /**
     * Resolve the persisted place using the explicit DIF place first and the
     * same city/province/country fallback for audit and apply paths.
     *
     * @param  array<string, mixed>  $location
     */
    public static function place(array $location): ?string
    {
        $place = $location['place'] ?? null;
        if (is_string($place) && trim($place) !== '') {
            return $place;
        }

        $parts = [];
        foreach (['city', 'province', 'country'] as $field) {
            $value = $location[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            if (! in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }
}
