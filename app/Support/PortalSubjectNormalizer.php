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

    public const SCHEME_SIMPLE_LITHOLOGY = 'CGI Simple Lithology';

    /**
     * Manually verified source identities that are equivalent to a current MSL
     * node. Never infer these mappings from a shared label: the same WP16 leaf
     * may occur in distinct Analogue and Rock Physics categories.
     *
     * @var array<string, array<string, string>>
     */
    private const LEGACY_MSL_CURRENT_NODE_URIS = [
        'epos wp16 analogue material' => [
            'http://epos/WP16Vocabulary/AnalogueMaterial/Rock/Granite' => 'https://epos-msl.uu.nl/voc/materials/1.3/igneous_rock_-_intrusive-acidic_intrusive-granite',
        ],
    ];

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
            LegacyMslScheme::isSupported($trimmed) => LegacyMslScheme::CANONICAL_SCHEME,
            str_contains($normalized, 'chronostrat') => self::SCHEME_ICS_CHRONOSTRAT,
            str_contains($normalized, 'gemet') => GemetVocabularyParser::SCHEME_TITLE,
            str_contains($normalized, 'analytical') && str_contains($normalized, 'method') => self::SCHEME_ANALYTICAL_METHODS,
            str_contains($normalized, 'euroscivoc'),
            str_contains($normalized, 'european science vocabulary') => 'European Science Vocabulary (EuroSciVoc)',
            $normalized === 'cgi simple lithology',
            $normalized === 'cgi simple lithology vocabulary' => self::SCHEME_SIMPLE_LITHOLOGY,
            default => $trimmed,
        };
    }

    public static function currentMslNodeUriForLegacyUri(?string $scheme, ?string $valueUri): ?string
    {
        $normalizedScheme = mb_strtolower(trim((string) $scheme));
        $normalizedValueUri = trim((string) $valueUri);
        if ($normalizedScheme === '' || $normalizedValueUri === '') {
            return null;
        }

        return self::LEGACY_MSL_CURRENT_NODE_URIS[$normalizedScheme][$normalizedValueUri] ?? null;
    }

    /**
     * @param  array<int, string>  $currentNodeUris
     * @return list<array{scheme: string, value_uri: string}>
     */
    public static function legacyMslUriAliasesForCurrentNodeUris(array $currentNodeUris): array
    {
        $selectedUris = array_fill_keys(array_map('trim', $currentNodeUris), true);
        $aliases = [];

        foreach (self::LEGACY_MSL_CURRENT_NODE_URIS as $normalizedScheme => $uriMappings) {
            foreach ($uriMappings as $legacyUri => $currentUri) {
                if (! isset($selectedUris[$currentUri])) {
                    continue;
                }

                $aliases[] = [
                    'scheme' => $normalizedScheme,
                    'value_uri' => $legacyUri,
                ];
            }
        }

        return $aliases;
    }

    public static function normalizedControlledSubjectValueSql(string $column, ?string $driverName = null): string
    {
        $characterFunction = self::characterCodeSqlFunction($driverName);
        $expression = 'LOWER('.self::trimmedSql($column).')';
        $expression = "REPLACE({$expression}, {$characterFunction}(13), ' ')";
        $expression = "REPLACE({$expression}, {$characterFunction}(10), ' ')";
        $expression = "REPLACE({$expression}, {$characterFunction}(9), ' ')";
        $expression = "REPLACE({$expression}, '&amp;gt;', '>')";
        $expression = "REPLACE({$expression}, '&amp;gt', '>')";
        $expression = "REPLACE({$expression}, '&gt;', '>')";
        $expression = "REPLACE({$expression}, '&gt', '>')";
        $expression = "REPLACE(REPLACE(REPLACE({$expression}, ' > ', '>'), ' >', '>'), '> ', '>')";
        $expression = "REPLACE({$expression}, '>', ' > ')";

        for ($i = 0; $i < 8; $i++) {
            $expression = "REPLACE({$expression}, '  ', ' ')";
        }

        return "TRIM({$expression})";
    }

    public static function normalizedSchemeSql(string $column): string
    {
        $trimmed = self::trimmedSql($column);
        $lowered = "LOWER({$trimmed})";
        $legacyMslSchemes = implode(', ', array_map(
            static fn (string $scheme): string => "'".str_replace("'", "''", mb_strtolower($scheme))."'",
            LegacyMslScheme::schemes(),
        ));

        $sql = sprintf(<<<'SQL'
CASE
    WHEN %1$s LIKE '%%science keywords%%' THEN 'science keywords'
    WHEN %1$s LIKE '%%platform%%' THEN 'platforms'
    WHEN %1$s LIKE '%%instrument%%' THEN 'instruments'
    WHEN %1$s LIKE '%%epos msl%%' OR %1$s LIKE '%%msl vocabulary%%' THEN 'epos msl vocabulary'
    WHEN %1$s IN (__LEGACY_MSL_SCHEMES__) THEN 'epos msl vocabulary'
    WHEN %1$s LIKE '%%chronostrat%%' THEN 'international chronostratigraphic chart'
    WHEN %1$s LIKE '%%gemet%%' THEN 'gemet - general multilingual environmental thesaurus'
    WHEN %1$s LIKE '%%analytical%%' AND %1$s LIKE '%%method%%' THEN 'analytical methods for geochemistry and cosmochemistry'
    WHEN %1$s LIKE '%%euroscivoc%%' OR %1$s LIKE '%%european science vocabulary%%' THEN 'european science vocabulary (euroscivoc)'
    WHEN %1$s = 'cgi simple lithology' OR %1$s = 'cgi simple lithology vocabulary' THEN 'cgi simple lithology'
    ELSE LOWER(%2$s)
END
SQL, $lowered, $trimmed);

        return str_replace('__LEGACY_MSL_SCHEMES__', $legacyMslSchemes, $sql);
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
