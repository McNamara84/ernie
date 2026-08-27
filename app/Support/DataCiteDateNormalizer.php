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

    public static function isDateOnly(?string $date): bool
    {
        if ($date === null) {
            return false;
        }

        $date = trim($date);

        return $date !== '' && self::normalize($date) === $date;
    }

    /**
     * Compare valid reduced-precision dates without inventing precision.
     *
     * A range is considered reversed only when the earliest possible start is
     * after the latest possible end. Overlapping reduced-precision values are
     * therefore accepted.
     */
    public static function isRangeReversed(string $startDate, string $endDate): bool
    {
        $startDate = trim($startDate);
        $endDate = trim($endDate);

        if (! self::isDateOnly($startDate) || ! self::isDateOnly($endDate)) {
            return false;
        }

        return self::lowerBound($startDate) > self::upperBound($endDate);
    }

    private static function lowerBound(string $date): string
    {
        return match (strlen($date)) {
            4 => $date.'-01-01',
            7 => $date.'-01',
            default => $date,
        };
    }

    private static function upperBound(string $date): string
    {
        if (strlen($date) === 4) {
            return $date.'-12-31';
        }

        if (strlen($date) === 7) {
            [$year, $month] = array_map('intval', explode('-', $date));
            $isLeapYear = $year % 400 === 0 || ($year % 4 === 0 && $year % 100 !== 0);
            $lastDay = match ($month) {
                2 => $isLeapYear ? 29 : 28,
                4, 6, 9, 11 => 30,
                default => 31,
            };

            return sprintf('%s-%02d', $date, $lastDay);
        }

        return $date;
    }

    private static function hasIsoDateTimeSuffix(string $suffix): bool
    {
        return preg_match(
            '/^[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:[.,][0-9]+)?(?:[Zz]|[+-][0-9]{2}:?[0-9]{2})?$/',
            $suffix,
        ) === 1;
    }
}
