<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared, dependency-free ORCID normalization and offline validation.
 *
 * Centralizes ORCID URL stripping, format validation, and ISO 7064 MOD 11-2
 * checksum verification so every consumer uses the same logic.
 */
final class OrcidNormalizer
{
    /**
     * Known ORCID URL prefixes (checked case-insensitively).
     *
     * @var list<string>
     */
    private const PREFIXES = [
        'https://orcid.org/',
        'http://orcid.org/',
        'https://www.orcid.org/',
        'http://www.orcid.org/',
    ];

    /**
     * Bare ORCID pattern: XXXX-XXXX-XXXX-XXXY where Y is 0-9 or X.
     */
    private const PATTERN = '/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/';

    /**
     * Strip any known ORCID URL prefix, returning the bare ID.
     *
     * Examples:
     *  - "https://orcid.org/0000-0002-1825-0097"  → "0000-0002-1825-0097"
     *  - "https://www.orcid.org/0000-0002-1825-0097" → "0000-0002-1825-0097"
     *  - "0000-0002-1825-0097" → "0000-0002-1825-0097"
     */
    public static function extractBareId(string $orcid): string
    {
        $normalized = trim($orcid);

        foreach (self::PREFIXES as $prefix) {
            if (stripos($normalized, $prefix) === 0) {
                return substr($normalized, strlen($prefix));
            }
        }

        return $normalized;
    }

    /**
     * Normalize an ORCID value to a canonical full URL (https://orcid.org/...).
     */
    public static function toUrl(string $orcid): string
    {
        return 'https://orcid.org/'.self::extractBareId($orcid);
    }

    /**
     * Validate the bare ORCID format (XXXX-XXXX-XXXX-XXXY).
     */
    public static function isValidFormat(string $orcid): bool
    {
        return (bool) preg_match(self::PATTERN, self::extractBareId($orcid));
    }

    /**
     * Validate the ISO 7064 MOD 11-2 checksum.
     *
     * Accepts both bare IDs and full URLs.
     *
     * @see https://support.orcid.org/hc/en-us/articles/360006897674-Structure-of-the-ORCID-Identifier
     */
    public static function isValidChecksum(string $orcid): bool
    {
        $digits = str_replace('-', '', self::extractBareId($orcid));

        if (strlen($digits) !== 16) {
            return false;
        }

        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            if (! ctype_digit($digits[$i])) {
                return false;
            }
            $total = ($total + (int) $digits[$i]) * 2;
        }

        $remainder = $total % 11;
        $expectedCheck = (12 - $remainder) % 11;
        $expectedChar = $expectedCheck === 10 ? 'X' : (string) $expectedCheck;

        return strtoupper($digits[15]) === $expectedChar;
    }

    /**
     * Full offline validation: format + checksum.
     *
     * Accepts both bare IDs and full URLs.
     */
    public static function isValid(string $orcid): bool
    {
        return self::isValidFormat($orcid) && self::isValidChecksum($orcid);
    }
}
