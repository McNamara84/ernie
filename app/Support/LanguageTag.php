<?php

declare(strict_types=1);

namespace App\Support;

final class LanguageTag
{
    private const PATTERN = '/\A[a-zA-Z]{1,8}(?:-[a-zA-Z0-9]{1,8})*\z/';

    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim((string) $value)));

        return $normalized !== '' ? $normalized : null;
    }

    public static function validOrNull(mixed $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized !== null && self::isValid($normalized) ? $normalized : null;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return list<string>
     */
    public static function validationRules(): array
    {
        return ['nullable', 'string', 'max:35', 'regex:'.self::PATTERN];
    }
}
