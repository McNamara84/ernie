<?php

namespace App\Support;

use Stringable;

final class BooleanNormalizer
{
    private const TRUE_VALUES = ['1', 'true', 'on', 'yes'];

    private function __construct() {}

    public static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === '') {
                return false;
            }

            return in_array($normalized, self::TRUE_VALUES, true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_float($value)) {
            return (int) $value === 1;
        }

        return false;
    }
}
