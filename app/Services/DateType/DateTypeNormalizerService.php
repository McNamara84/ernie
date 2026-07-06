<?php

declare(strict_types = 1);

namespace App\Services\DateType;

use DateTime;

final class DateTypeNormalizerService
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) 
        {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') 
        {
            return null;
        }

        if (preg_match('/^\d{4}$/', $trimmed)) 
        {
            return $trimmed;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $trimmed, $matches)) 
        {
            $month = (int) $matches[2];
            if ($month >= 1 && $month <= 12)
            {
                return $trimmed;
            } 
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches)) 
        {
            if (checkdate(
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[1],
            ))
            {
                return $trimmed;
            }
            return null;
        }

          if (substr_count($trimmed, '/') === 1)   
        {
            [$start, $end] = array_map('trim', explode('/', $trimmed));

            $normalizedStart = self::normalize($start);
            $normalizedEnd = self::normalize($end);

            if ($normalizedStart === null || $normalizedEnd === null) {
                return null;
            }

            return $normalizedStart.'/'.$normalizedEnd;
        }


        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $trimmed, $matches)) {
            if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                return null;
            }

            $suffix = $matches[4];

            if ($suffix === '') {
                return $trimmed;
            }

            if (! self::hasIsoDateTimeSuffix($suffix)) {
                return null;
            }

            return $trimmed;
        }

        // d = Tag mit führender Null
        // j = Tag ohne führende Null
        // m = Monat mit führender Null
        // n = Monat ohne führende Null
        // Y = Jahr mit vier Stellen
        // y = Jahr mit zwei Stellen
        $formats = [
            'd.m.Y',
            'd.m.y', 
            'Y.m.d',
            'j.n.Y',
            'Y.n.j',
            'j/n/Y',
            'Y/n/j',
            'd/m/Y',
            'Y/m/d',
            'Y-n-j',
        ];

        foreach ($formats as $format)
        {
            $date = DateTime::createFromFormat($format, $trimmed);

            if ($date !== false && $date->format($format) === $trimmed)
            {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    private static function hasIsoDateTimeSuffix(string $suffix): bool
    {
        return preg_match(
            '/^[T ]\d{2}:\d{2}(?::\d{2})?(?:[.,]\d+)?(?:[Zz]|[+-]\d{2}:?\d{2})?$/',
            $suffix,
        ) === 1;
    }
}