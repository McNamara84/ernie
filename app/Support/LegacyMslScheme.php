<?php

declare(strict_types=1);

namespace App\Support;

final class LegacyMslScheme
{
    public const CANONICAL_SCHEME = 'EPOS MSL vocabulary';

    /** @var list<string> */
    private const AREAS = [
        'Analogue',
        'Rock Physics',
    ];

    /** @var list<string> */
    private const CATEGORIES = [
        'Material',
        'Apparatus',
        'Monitoring',
        'Software',
        'Measured Property',
        'Main Setting',
        'Geologic Feature',
        'Geologic Structure',
        'Process/Hazard',
    ];

    public static function isSupported(?string $scheme): bool
    {
        $scheme = mb_strtolower(trim((string) $scheme));

        return $scheme !== '' && in_array($scheme, self::normalizedSchemes(), true);
    }

    /** @return list<string> */
    public static function normalizedSchemes(): array
    {
        return array_map(
            static fn (string $scheme): string => mb_strtolower($scheme),
            self::schemes(),
        );
    }

    /** @return list<string> */
    public static function schemes(): array
    {
        $schemes = [];

        foreach (self::AREAS as $area) {
            foreach (self::CATEGORIES as $category) {
                $schemes[] = "EPOS WP16 {$area} {$category}";
            }
        }

        return $schemes;
    }
}
