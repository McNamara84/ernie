<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes DataCite/RKMS-ISO8601 date strings without inventing precision.
 */
final readonly class DataCiteDateNormalizer
{
    public static function normalize(?string $date, bool $preserveDateTime = false): ?string
    {
        if ($date === null) {
            return null;
        }

        $date = trim($date);

        if ($date === '') {
            return null;
        }

        if (preg_match('/^([0-9]{4})$/', $date) === 1) {
            return $date;
        }

        if (preg_match('/^([0-9]{4})-([0-9]{2})$/', $date, $matches) === 1) {
            $month = (int) $matches[2];

            return $month >= 1 && $month <= 12 ? $date : null;
        }

        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(.*)$/', $date, $matches) !== 1) {
            return null;
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        $suffix = $matches[4];
        if ($suffix === '') {
            return $date;
        }

        if (! self::hasIsoDateTimeSuffix($suffix)) {
            return null;
        }

        return $preserveDateTime ? $date : substr($date, 0, 10);
    }

    private static function hasIsoDateTimeSuffix(string $suffix): bool
    {
        return preg_match(
            '/^[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:[.,][0-9]+)?(?:[Zz]|[+-][0-9]{2}:?[0-9]{2})?$/',
            $suffix,
        ) === 1;
    }
}
