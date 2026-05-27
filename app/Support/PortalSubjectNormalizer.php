<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class PortalSubjectNormalizer
{
    public const BREADCRUMB_SEPARATOR = ' > ';

    private const ENCODED_BREADCRUMB_SEPARATOR_PATTERN = '/\s*&(?:amp;)?gt;?\s*/iu';

    public const SCHEME_ICS_CHRONOSTRAT = 'International Chronostratigraphic Chart';

    public const SCHEME_ANALYTICAL_METHODS = 'Analytical Methods for Geochemistry and Cosmochemistry';

    public static function normalizeControlledSubjectValue(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $decodedEntitySeparators = preg_replace(self::ENCODED_BREADCRUMB_SEPARATOR_PATTERN, self::BREADCRUMB_SEPARATOR, $trimmed) ?? $trimmed;
        $normalizedSeparators = preg_replace('/\s*>\s*/u', self::BREADCRUMB_SEPARATOR, $decodedEntitySeparators) ?? $decodedEntitySeparators;
        $normalizedWhitespace = preg_replace('/\s+/u', ' ', $normalizedSeparators) ?? $normalizedSeparators;
        $normalizedValue = trim($normalizedWhitespace);

        return $normalizedValue === '' ? null : $normalizedValue;
    }

    public static function normalizeScheme(?string $scheme): ?string
    {
        $trimmed = trim((string) $scheme);
        if ($trimmed === '') {
            return null;
        }

        $normalized = mb_strtolower($trimmed);

        return match (true) {
            str_contains($normalized, 'science keywords') => 'Science Keywords',
            str_contains($normalized, 'platform') => 'Platforms',
            str_contains($normalized, 'instrument') => 'Instruments',
            str_contains($normalized, 'epos msl'),
            str_contains($normalized, 'msl vocabulary') => 'EPOS MSL vocabulary',
            str_contains($normalized, 'chronostrat') => self::SCHEME_ICS_CHRONOSTRAT,
            str_contains($normalized, 'gemet') => GemetVocabularyParser::SCHEME_TITLE,
            str_contains($normalized, 'analytical') && str_contains($normalized, 'method') => self::SCHEME_ANALYTICAL_METHODS,
            str_contains($normalized, 'euroscivoc'),
            str_contains($normalized, 'european science vocabulary') => 'European Science Vocabulary (EuroSciVoc)',
            default => $trimmed,
        };
    }

    public static function normalizedControlledSubjectValueSql(string $column, ?string $driverName = null): string
    {
        $characterFunction = self::characterCodeSqlFunction($driverName);
        $expression = self::trimmedSql($column);
        $expression = "REPLACE({$expression}, {$characterFunction}(13), ' ')";
        $expression = "REPLACE({$expression}, {$characterFunction}(10), ' ')";
        $expression = "REPLACE({$expression}, {$characterFunction}(9), ' ')";
        $expression = "REPLACE({$expression}, '&amp;gt;', '>')";
        $expression = "REPLACE({$expression}, '&gt;', '>')";
        $expression = "REPLACE({$expression}, '&gt', '>')";
        $expression = "REPLACE(REPLACE(REPLACE({$expression}, ' > ', '>'), ' >', '>'), '> ', '>')";
        $expression = "REPLACE({$expression}, '>', ' > ')";

        for ($i = 0; $i < 8; $i++) {
            $expression = "REPLACE({$expression}, '  ', ' ')";
        }

        return "LOWER(TRIM({$expression}))";
    }

    public static function normalizedSchemeSql(string $column): string
    {
        $trimmed = self::trimmedSql($column);
        $lowered = "LOWER({$trimmed})";

        return sprintf(<<<'SQL'
CASE
    WHEN %1$s LIKE '%%science keywords%%' THEN 'science keywords'
    WHEN %1$s LIKE '%%platform%%' THEN 'platforms'
    WHEN %1$s LIKE '%%instrument%%' THEN 'instruments'
    WHEN %1$s LIKE '%%epos msl%%' OR %1$s LIKE '%%msl vocabulary%%' THEN 'epos msl vocabulary'
    WHEN %1$s LIKE '%%chronostrat%%' THEN 'international chronostratigraphic chart'
    WHEN %1$s LIKE '%%gemet%%' THEN 'gemet - general multilingual environmental thesaurus'
    WHEN %1$s LIKE '%%analytical%%' AND %1$s LIKE '%%method%%' THEN 'analytical methods for geochemistry and cosmochemistry'
    WHEN %1$s LIKE '%%euroscivoc%%' OR %1$s LIKE '%%european science vocabulary%%' THEN 'european science vocabulary (euroscivoc)'
    ELSE LOWER(%2$s)
END
SQL, $lowered, $trimmed);
    }

    private static function trimmedSql(string $column): string
    {
        return "TRIM(COALESCE({$column}, ''))";
    }

    private static function characterCodeSqlFunction(?string $driverName = null): string
    {
        return ($driverName ?? DB::connection()->getDriverName()) === 'pgsql'
            ? 'CHR'
            : 'CHAR';
    }
}