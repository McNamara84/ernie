<?php

declare(strict_types=1);

namespace App\Services\DataCite;

use Opis\JsonSchema\Resolvers\FormatResolver;

/**
 * Executable DataCite 4.7 date formats for the internal JSON Schema.
 *
 * DataCite dates use the W3CDTF granularities and may additionally be
 * expressed as closed or open RKMS-ISO8601 ranges.
 */
final class DataCiteDateFormat
{
    public const YEAR = 'datacite-year';

    public const DATE = 'datacite-date';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::YEAR,
            self::DATE,
        ];
    }

    public static function register(FormatResolver $resolver): void
    {
        $resolver->registerCallable('string', self::YEAR, self::isPublicationYear(...));
        $resolver->registerCallable('string', self::DATE, self::isDate(...));
    }

    public static function isPublicationYear(string $value): bool
    {
        return preg_match('/^\d{4}$/D', $value) === 1;
    }

    public static function isDate(string $value): bool
    {
        if (substr_count($value, '/') === 0) {
            return self::isW3cDate($value);
        }

        if (substr_count($value, '/') !== 1) {
            return false;
        }

        [$start, $end] = explode('/', $value, 2);

        if ($start === '' && $end === '') {
            return false;
        }

        return ($start === '' || self::isW3cDate($start))
            && ($end === '' || self::isW3cDate($end));
    }

    private static function isW3cDate(string $value): bool
    {
        $pattern = '/^(?<year>-?\d{4})'
            .'(?:-(?<month>\d{2})'
            .'(?:-(?<day>\d{2})'
            .'(?:T(?<hour>\d{2}):(?<minute>\d{2})'
            .'(?::(?<second>\d{2})(?:\.(?<fraction>\d+))?)?'
            .'(?<timezone>Z|[+-]\d{2}:\d{2}))?'
            .')?'
            .')?$/D';

        if (preg_match($pattern, $value, $matches) !== 1) {
            return false;
        }

        if (! isset($matches['month']) || $matches['month'] === '') {
            return true;
        }

        $month = (int) $matches['month'];
        if ($month < 1 || $month > 12) {
            return false;
        }

        if (! isset($matches['day']) || $matches['day'] === '') {
            return true;
        }

        $year = (int) $matches['year'];
        $day = (int) $matches['day'];
        if ($day < 1 || $day > self::daysInMonth($year, $month)) {
            return false;
        }

        if (! isset($matches['hour']) || $matches['hour'] === '') {
            return true;
        }

        if (! isset($matches['minute'], $matches['timezone'])) {
            return false;
        }

        if ((int) $matches['hour'] > 23 || (int) $matches['minute'] > 59) {
            return false;
        }

        if (isset($matches['second']) && $matches['second'] !== '' && (int) $matches['second'] > 59) {
            return false;
        }

        $timezone = $matches['timezone'];
        if ($timezone !== 'Z') {
            $timezoneHour = (int) substr($timezone, 1, 2);
            $timezoneMinute = (int) substr($timezone, 4, 2);

            if ($timezoneHour > 23 || $timezoneMinute > 59) {
                return false;
            }
        }

        return true;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    private static function isLeapYear(int $year): bool
    {
        return $year % 400 === 0 || ($year % 4 === 0 && $year % 100 !== 0);
    }
}
