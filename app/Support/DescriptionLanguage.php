<?php

declare(strict_types=1);

namespace App\Support;

final class DescriptionLanguage
{
    /** @var list<string> */
    public const DETECTOR_CODES = ['de', 'en'];

    public static function isDetectorLanguage(mixed $value): bool
    {
        $language = LanguageTag::normalize($value);

        return $language !== null && in_array($language, self::DETECTOR_CODES, true);
    }

    public static function label(string $code): string
    {
        return match (LanguageTag::normalize($code)) {
            'de' => 'German',
            'en' => 'English',
            default => strtoupper($code),
        };
    }
}
