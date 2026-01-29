<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects funderIdentifierType from funderIdentifier URLs.
 *
 * Supports DataCite 4.6 funderIdentifierType values:
 * - ROR (Research Organization Registry)
 * - Crossref Funder ID (DOI prefix 10.13039)
 * - ISNI (International Standard Name Identifier)
 * - GRID (Global Research Identifier Database - deprecated)
 * - Other (fallback)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/fundingreference/#a-funderidentifiertype
 */
final class FunderIdentifierTypeDetector
{
    public const TYPE_ROR = 'ROR';

    public const TYPE_CROSSREF = 'Crossref Funder ID';

    public const TYPE_ISNI = 'ISNI';

    public const TYPE_GRID = 'GRID';

    public const TYPE_OTHER = 'Other';

    /**
     * Detect the funderIdentifierType from a funderIdentifier.
     *
     * @param  string|null  $identifier  The funder identifier (URL or formatted ID)
     * @return string|null The detected type, or null if no identifier provided
     */
    public static function detect(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        $identifier = trim($identifier);

        // Check for ROR
        if (self::isRor($identifier)) {
            return self::TYPE_ROR;
        }

        // Check for Crossref Funder ID
        if (self::isCrossrefFunderId($identifier)) {
            return self::TYPE_CROSSREF;
        }

        // Check for ISNI
        if (self::isIsni($identifier)) {
            return self::TYPE_ISNI;
        }

        // Check for GRID
        if (self::isGrid($identifier)) {
            return self::TYPE_GRID;
        }

        // Fallback to Other if identifier is present but not recognized
        return self::TYPE_OTHER;
    }

    /**
     * Check if identifier is a ROR ID.
     * Pattern: ror.org/XXXXXXX (9 alphanumeric characters)
     */
    private static function isRor(string $identifier): bool
    {
        $normalized = self::normalizeUrl($identifier);

        return str_contains($normalized, 'ror.org/');
    }

    /**
     * Check if identifier is a Crossref Funder ID.
     * Pattern: doi.org/10.13039/XXXXX
     */
    private static function isCrossrefFunderId(string $identifier): bool
    {
        $normalized = self::normalizeUrl($identifier);

        // Must contain doi.org and the Crossref Funder Registry DOI prefix
        if (! str_contains($normalized, 'doi.org/')) {
            return false;
        }

        // Check for the 10.13039 prefix (Crossref Funder Registry)
        return (bool) preg_match('#doi\.org/10\.13039/#i', $normalized);
    }

    /**
     * Check if identifier is an ISNI.
     * Patterns:
     * - URL: isni.org/isni/XXXXXXXXXXXXXXXX
     * - Formatted: 0000 0001 2162 673X (4 groups of 4 digits/X)
     * - Raw: 000000012162673X (16 characters)
     */
    private static function isIsni(string $identifier): bool
    {
        $normalized = self::normalizeUrl($identifier);

        // Check URL pattern
        if (str_contains($normalized, 'isni.org')) {
            return true;
        }

        // Check formatted/raw ISNI pattern (16 digits/X, optionally with spaces)
        $cleaned = (string) preg_replace('/[\s-]/', '', $identifier);

        return (bool) preg_match('/^[0-9]{15}[0-9X]$/i', $cleaned);
    }

    /**
     * Check if identifier is a GRID ID (deprecated).
     * Pattern: grid.ac/institutes/grid.XXXXX
     */
    private static function isGrid(string $identifier): bool
    {
        $normalized = self::normalizeUrl($identifier);

        return str_contains($normalized, 'grid.ac');
    }

    /**
     * Normalize URL for pattern matching.
     * Removes scheme and www prefix, converts to lowercase.
     */
    private static function normalizeUrl(string $url): string
    {
        $url = strtolower($url);
        $url = (string) preg_replace('#^https?://#', '', $url);

        return (string) preg_replace('#^www\.#', '', $url);
    }
}
