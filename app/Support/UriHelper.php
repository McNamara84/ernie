<?php

declare(strict_types=1);

namespace App\Support;

use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

/**
 * Helper class for RFC 3986 compliant URI parsing using PHP 8.5's Uri extension.
 *
 * This class wraps PHP 8.5's native Uri\Rfc3986\Uri class to provide
 * convenient methods for common URL operations with proper error handling.
 *
 * @see https://wiki.php.net/rfc/url_parsing_api
 */
final class UriHelper
{
    /**
     * Parse a URL string into a Uri object.
     *
     * @param  string  $url  The URL to parse
     * @return Uri|null The parsed Uri object, or null if parsing fails
     */
    public static function parse(string $url): ?Uri
    {
        try {
            return Uri::parse($url);
        } catch (InvalidUriException) {
            return null;
        }
    }

    /**
     * Extract the scheme from a URL.
     *
     * @param  string  $url  The URL to parse
     * @return string|null The scheme (e.g., 'https'), or null if not present/invalid
     */
    public static function getScheme(string $url): ?string
    {
        return self::parse($url)?->getScheme();
    }

    /**
     * Extract the host from a URL.
     *
     * @param  string  $url  The URL to parse
     * @return string|null The host, or null if not present/invalid
     */
    public static function getHost(string $url): ?string
    {
        return self::parse($url)?->getHost();
    }

    /**
     * Extract the path from a URL.
     *
     * @param  string  $url  The URL to parse
     * @return string|null The path, or null if parsing fails
     */
    public static function getPath(string $url): ?string
    {
        return self::parse($url)?->getPath();
    }

    /**
     * Extract the query string from a URL.
     *
     * @param  string  $url  The URL to parse
     * @return string|null The query string (without leading '?'), or null if not present
     */
    public static function getQuery(string $url): ?string
    {
        return self::parse($url)?->getQuery();
    }

    /**
     * Extract query parameters as an associative array.
     *
     * Note: Falls back to parse_url() for URLs with unencoded special characters
     * (like `page[cursor]`) that are technically invalid per RFC 3986 but common in practice.
     *
     * @param  string  $url  The URL to parse
     * @return array<int|string, mixed> The query parameters as an array
     */
    public static function getQueryParams(string $url): array
    {
        $query = self::getQuery($url);

        // If RFC 3986 parsing fails (e.g., unencoded brackets), fall back to parse_url
        if ($query === null) {
            $legacyParsed = parse_url($url);
            $query = $legacyParsed['query'] ?? null;
        }

        if ($query === null || $query === '') {
            return [];
        }

        parse_str($query, $params);

        return $params;
    }

    /**
     * Check if a URL is valid according to RFC 3986.
     *
     * @param  string  $url  The URL to validate
     * @return bool True if the URL is valid
     */
    public static function isValid(string $url): bool
    {
        return self::parse($url) !== null;
    }

    /**
     * Check if a URL uses a specific scheme.
     *
     * @param  string  $url  The URL to check
     * @param  string|array<string>  $schemes  The scheme(s) to check for (case-insensitive)
     * @return bool True if the URL uses one of the specified schemes
     */
    public static function hasScheme(string $url, string|array $schemes): bool
    {
        $urlScheme = self::getScheme($url);

        if ($urlScheme === null) {
            return false;
        }

        $schemes = is_array($schemes) ? $schemes : [$schemes];
        $urlSchemeLower = strtolower($urlScheme);

        foreach ($schemes as $scheme) {
            if (strtolower($scheme) === $urlSchemeLower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL uses HTTP or HTTPS scheme.
     *
     * @param  string  $url  The URL to check
     * @return bool True if the URL uses http or https
     */
    public static function isHttpUrl(string $url): bool
    {
        return self::hasScheme($url, ['http', 'https']);
    }
}
