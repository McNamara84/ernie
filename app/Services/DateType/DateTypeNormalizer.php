<?php

declare(strict_types = 1);

namespace App\Services\DateType;

use DateTime;

final class DateTypeNormalizerService
{
    public static function normalize(string $value) :string
    {
        $trimmed = trim($value);

        if ($trimmed === '') 
        {
            return '';
        }

        if (preg_match('/^-?\d{4}$/', $trimmed)) 
        {
            return $trimmed;
        }

        if (preg_match('/^-?\d{4}-\d{2}$/', $trimmed)) 
        {
            return $trimmed;
        }

        if (preg_match('/^-?\d{4}-\d{2}-\d{2}$/', $trimmed)) 
        {
            return $trimmed;
            }

        if (preg_match('/^-?\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $trimmed))  
        {
            return $trimmed;
        }

        if (substr_count($trimmed, '/') === 1)   
        {
            [$start, $end] = array_map('trim', explode('/', $trimmed));

            $normalizedStart = self::normalize($start);
            $normalizedEnd = self::normalize($end);

            if ($normalizedStart === '' || $normalizedEnd === '') {
                return '';
            }

            return $normalizedStart.'/'.$normalizedEnd;
        }

        // d = Tag mit führender Null
        // j = Tag ohne führende Null
        // m = Monat mit führender Null
        // n = Monat ohne führende Null
        // Y = Jahr mit vier Stellen
        // y = Jahr mit zwei Stellen
        $formats = [
            'd.m.Y', 
            'Y.m.d',
            'j.n.Y',
            'Y.n.j',
            'j/n/Y',
            'Y/n/j',
            'd/m/Y',
            'Y/m/d',
            'Y-n-j',
            'Y-m-d',
        ];

        foreach ($formats as $format)
        {
            $date = DateTime::createFromFormat($format, $trimmed);

            if ($date !== false)
            {
                return $date->format('Y-m-d');
            }
        }
        return '';
    }
}