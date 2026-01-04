<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service for generating URL-friendly slugs from titles.
 *
 * Features:
 * - ASCII transliteration (German umlauts, accented characters)
 * - Special character removal
 * - Lowercase conversion
 * - Word boundary truncation (minimum length, then next word end)
 */
class SlugGeneratorService
{
    /**
     * Default minimum length before truncation at word boundary.
     */
    private const DEFAULT_MIN_LENGTH = 40;

    /**
     * Transliteration map for special characters.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATION_MAP = [
        // German umlauts
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'Ä' => 'Ae',
        'Ö' => 'Oe',
        'Ü' => 'Ue',
        'ß' => 'ss',
        // French accents
        'à' => 'a',
        'â' => 'a',
        'ç' => 'c',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'î' => 'i',
        'ï' => 'i',
        'ô' => 'o',
        'ù' => 'u',
        'û' => 'u',
        'ÿ' => 'y',
        // Spanish
        'ñ' => 'n',
        'á' => 'a',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        // Portuguese
        'ã' => 'a',
        'õ' => 'o',
        // Nordic
        'æ' => 'ae',
        'ø' => 'o',
        'å' => 'a',
        'Æ' => 'Ae',
        'Ø' => 'O',
        'Å' => 'A',
        // Polish
        'ł' => 'l',
        'Ł' => 'L',
        'ź' => 'z',
        'ż' => 'z',
        'ć' => 'c',
        'ś' => 's',
        'ń' => 'n',
        // Czech/Slovak
        'č' => 'c',
        'ř' => 'r',
        'š' => 's',
        'ž' => 'z',
        'ď' => 'd',
        'ť' => 't',
        'ň' => 'n',
        'ě' => 'e',
        'ů' => 'u',
        // Other common characters
        "\u{2013}" => '-', // en-dash
        "\u{2014}" => '-', // em-dash
        "\u{2018}" => '',  // left single quote
        "\u{2019}" => '',  // right single quote
        "\u{201C}" => '',  // left double quote
        "\u{201D}" => '',  // right double quote
        "\u{2026}" => '',  // ellipsis
    ];

    /**
     * Generate a URL-friendly slug from a title.
     *
     * @param  string  $title  The original title
     * @param  int  $minLength  Minimum length before truncation (default: 40)
     * @return string The generated slug
     */
    public function generateFromTitle(string $title, int $minLength = self::DEFAULT_MIN_LENGTH): string
    {
        // Step 1: Transliterate special characters to ASCII
        $slug = $this->transliterate($title);

        // Step 2: Convert to lowercase
        $slug = mb_strtolower($slug);

        // Step 3: Replace spaces and underscores with hyphens
        $slug = preg_replace('/[\s_]+/', '-', $slug) ?? $slug;

        // Step 4: Remove all characters except letters, numbers, and hyphens
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? $slug;

        // Step 5: Replace multiple consecutive hyphens with single hyphen
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;

        // Step 6: Trim hyphens from start and end
        $slug = trim($slug, '-');

        // Step 7: Truncate at word boundary if longer than minimum length
        if (mb_strlen($slug) > $minLength) {
            $slug = $this->truncateAtWordBoundary($slug, $minLength);
        }

        // Fallback for empty slugs
        if ($slug === '') {
            $slug = 'dataset';
        }

        return $slug;
    }

    /**
     * Transliterate special characters to ASCII equivalents.
     *
     * Note: The iconv() function's behavior is locale-dependent, which can lead to
     * inconsistent transliteration results across different server environments.
     * We mitigate this by:
     * 1. Using a comprehensive TRANSLITERATION_MAP for common characters first
     * 2. Only relying on iconv as a fallback for remaining characters
     * 3. Logging failures to help diagnose locale-related issues
     * 4. Setting LC_CTYPE to 'C.UTF-8' before iconv calls for consistent behavior
     *
     * THREAD-SAFETY WARNING: The setlocale() call used here is NOT thread-safe.
     * In multi-threaded PHP environments (Swoole, ReactPHP, parallel extensions),
     * changing the locale in one thread can affect other threads. This is acceptable
     * for traditional PHP-FPM deployments (which are process-based, not threaded)
     * but would require refactoring for async/threaded environments. Consider using
     * Symfony's AsciiSlugger (symfony/string) for thread-safe transliteration.
     *
     * For fully deterministic and thread-safe behavior, consider using symfony/string's
     * AsciiSlugger as an alternative. The current approach is sufficient for GFZ's
     * deployment environment where PHP-FPM provides process isolation.
     *
     * @param  string  $text  The text to transliterate
     * @return string The transliterated text
     */
    private function transliterate(string $text): string
    {
        // Apply custom transliteration map first
        $text = strtr($text, self::TRANSLITERATION_MAP);

        // Check if iconv extension is available (should always be, but defensive)
        if (! function_exists('iconv')) {
            \Illuminate\Support\Facades\Log::warning(
                'SlugGeneratorService: iconv extension not available, using fallback',
                ['text_length' => mb_strlen($text)]
            );

            return $text;
        }

        // Use iconv for any remaining non-ASCII characters.
        // TRANSLIT attempts to transliterate, //IGNORE removes untranslatable chars.
        //
        // Locale handling: Set LC_CTYPE to a UTF-8 locale for consistent transliteration.
        // This ensures the same behavior across different server environments.
        // We restore the original locale after the operation to avoid side effects.
        $originalLocale = setlocale(LC_CTYPE, '0');

        // Attempt to set a UTF-8 locale for consistent transliteration.
        // setlocale() returns false if none of the specified locales are available.
        // We try multiple common locale names for cross-platform compatibility.
        $localeSet = setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'POSIX');

        if ($localeSet === false) {
            // Log that we couldn't set a UTF-8 locale - transliteration may be inconsistent.
            // This is a warning rather than an error because the transliteration map
            // handles most common cases, and iconv may still work with the current locale.
            \Illuminate\Support\Facades\Log::warning(
                'SlugGeneratorService: Failed to set UTF-8 locale for transliteration',
                [
                    'original_locale' => $originalLocale,
                    'attempted_locales' => ['C.UTF-8', 'en_US.UTF-8', 'POSIX'],
                ]
            );
            // Proceed anyway - iconv may still work, and our TRANSLITERATION_MAP
            // handles the most common characters before iconv is called.
        }

        // Error handling approach:
        // We capture the last error before iconv to distinguish new errors from pre-existing ones.
        // This provides better debugging information when transliteration fails.
        // The @ operator is still needed because iconv generates notices for untranslatable
        // characters that we want to suppress (they're expected, not errors).
        $previousError = error_get_last();
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $currentError = error_get_last();

        // Restore original locale
        if ($originalLocale !== false) {
            $restored = setlocale(LC_CTYPE, $originalLocale);
            if ($restored === false) {
                // Log warning - locale restoration failed, process may be in unexpected state.
                // This is unlikely but could affect subsequent operations in the same request.
                \Illuminate\Support\Facades\Log::warning(
                    'SlugGeneratorService: Failed to restore original locale after transliteration',
                    [
                        'original_locale' => $originalLocale,
                        'current_locale' => setlocale(LC_CTYPE, '0'),
                    ]
                );
            }
        }

        if ($transliterated === false) {
            // Log transliteration failure with error details for debugging
            $errorMsg = ($currentError !== $previousError && $currentError !== null)
                ? $currentError['message']
                : 'Unknown error (possibly locale-dependent)';

            \Illuminate\Support\Facades\Log::debug(
                'SlugGeneratorService: iconv transliteration failed',
                [
                    'original_text_length' => mb_strlen($text),
                    'error' => $errorMsg,
                ]
            );

            return $text;
        }

        return $transliterated;
    }

    /**
     * Truncate text at word boundary after minimum length.
     *
     * In slugs, words are separated by hyphens. This method finds the first
     * hyphen position after minLength and truncates there.
     *
     * @param  string  $text  The text to truncate (already a slug with hyphens)
     * @param  int  $minLength  Minimum length before looking for word boundary
     * @return string The truncated text
     */
    private function truncateAtWordBoundary(string $text, int $minLength): string
    {
        // If text is shorter than or equal to minLength, return as-is
        if (mb_strlen($text) <= $minLength) {
            return $text;
        }

        // Find the next hyphen after minLength
        $nextHyphen = mb_strpos($text, '-', $minLength);

        if ($nextHyphen !== false) {
            // Truncate at the hyphen position (excluding the hyphen)
            return mb_substr($text, 0, $nextHyphen);
        }

        // No hyphen found after minLength, return full text
        // (the text ends at a natural word boundary)
        return $text;
    }
}
