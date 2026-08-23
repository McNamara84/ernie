<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

final class LanguageTag
{
    private const MAX_LENGTH = 35;

    /** @var list<string> */
    private const GRANDFATHERED_TAGS = [
        'art-lojban',
        'cel-gaulish',
        'en-gb-oed',
        'i-ami',
        'i-bnn',
        'i-default',
        'i-enochian',
        'i-hak',
        'i-klingon',
        'i-lux',
        'i-mingo',
        'i-navajo',
        'i-pwn',
        'i-tao',
        'i-tay',
        'i-tsu',
        'no-bok',
        'no-nyn',
        'sgn-be-fr',
        'sgn-be-nl',
        'sgn-ch-de',
        'zh-guoyu',
        'zh-hakka',
        'zh-min',
        'zh-min-nan',
        'zh-xiang',
    ];

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

    public static function primarySubtag(mixed $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        return explode('-', $normalized, 2)[0];
    }

    public static function isValid(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        $tag = strtolower($value);

        if (in_array($tag, self::GRANDFATHERED_TAGS, true)) {
            return true;
        }

        $subtags = explode('-', $tag);

        if (in_array('', $subtags, true)) {
            return false;
        }

        if ($subtags[0] === 'x') {
            return count($subtags) > 1 && self::allPrivateUseSubtags(array_slice($subtags, 1));
        }

        return self::isLangTag($subtags);
    }

    /**
     * @return list<string|Closure(string, mixed, Closure(string): void): void>
     */
    public static function validationRules(): array
    {
        return [
            'nullable',
            'string',
            'max:'.self::MAX_LENGTH,
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! self::isValid($value)) {
                    $fail("The {$attribute} field must be a valid BCP 47 language tag.");
                }
            },
        ];
    }

    /**
     * Validate the RFC 5646 `langtag` production and its uniqueness constraints.
     *
     * @param  list<string>  $subtags
     */
    private static function isLangTag(array $subtags): bool
    {
        $count = count($subtags);
        $index = 0;
        $language = $subtags[$index++];

        if (! self::matches('/\A[a-z]{2,8}\z/', $language)) {
            return false;
        }

        if (strlen($language) <= 3) {
            $extlangCount = 0;

            while ($index < $count && $extlangCount < 3 && self::matches('/\A[a-z]{3}\z/', $subtags[$index])) {
                $index++;
                $extlangCount++;
            }
        }

        if ($index < $count && self::matches('/\A[a-z]{4}\z/', $subtags[$index])) {
            $index++;
        }

        if ($index < $count && self::matches('/\A(?:[a-z]{2}|[0-9]{3})\z/', $subtags[$index])) {
            $index++;
        }

        $variants = [];

        while ($index < $count && self::matches('/\A(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3})\z/', $subtags[$index])) {
            $variant = $subtags[$index++];

            if (isset($variants[$variant])) {
                return false;
            }

            $variants[$variant] = true;
        }

        $extensionSingletons = [];

        while ($index < $count && self::matches('/\A[0-9a-wy-z]\z/', $subtags[$index])) {
            $singleton = $subtags[$index++];

            if (isset($extensionSingletons[$singleton])) {
                return false;
            }

            $extensionSingletons[$singleton] = true;
            $extensionStart = $index;

            while ($index < $count && self::matches('/\A[a-z0-9]{2,8}\z/', $subtags[$index])) {
                $index++;
            }

            if ($index === $extensionStart) {
                return false;
            }
        }

        if ($index < $count && $subtags[$index] === 'x') {
            $index++;

            if ($index === $count || ! self::allPrivateUseSubtags(array_slice($subtags, $index))) {
                return false;
            }

            $index = $count;
        }

        return $index === $count;
    }

    /** @param list<string> $subtags */
    private static function allPrivateUseSubtags(array $subtags): bool
    {
        foreach ($subtags as $subtag) {
            if (! self::matches('/\A[a-z0-9]{1,8}\z/', $subtag)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(string $pattern, string $value): bool
    {
        return preg_match($pattern, $value) === 1;
    }
}
