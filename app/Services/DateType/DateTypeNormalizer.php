<?php

declare(strict_types = 1);

namespace App\Services\DateType;

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

        if (str_contains($trimmed, '/')) 
        {
            $parts = explode('/', $trimmed);

            if (count($parts) !== 2) {
            return '';
            }

            $start = trim($parts[0]);
            $end = trim($parts[1]);

            $normalizedStart = self::normalize($start);
            $normalizedEnd = self::normalize($end);

            if ($normalizedStart === '' || $normalizedEnd === '') 
                {
                    return ''; 
            }
             return $normalizedStart.'/'.$normalizedEnd;
        }
        return '';
    }
}