<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LegacyMslScheme;

class MslKeywordTransformer
{
    /**
     * Transform an MSL keyword from old database format to new format.
     *
     * @param  object  $oldKeyword  Object with properties: keyword, thesaurus, uri, description
     * @return array<string, string|bool|null>|null Array with keys: id, text, path, language, scheme, schemeURI, description, isLegacy
     */
    public static function transform(object $oldKeyword): ?array
    {
        $thesaurus = $oldKeyword->thesaurus ?? '';
        $keyword = $oldKeyword->keyword ?? '';
        $oldUri = $oldKeyword->uri ?? '';
        $description = $oldKeyword->description ?? null;

        if (! LegacyMslScheme::isSupported($thesaurus)) {
            return null;
        }

        $path = trim((string) $keyword);
        if ($path === '') {
            return null;
        }

        $valueUri = filter_var($oldUri, FILTER_VALIDATE_URL)
            ? trim((string) $oldUri)
            : 'legacy:'.hash('sha256', mb_strtolower($thesaurus.'|'.$path));
        $text = self::extractLastPathSegment($path);

        return [
            'id' => $valueUri,
            'text' => $text,
            'path' => $path,
            'language' => 'en',
            'scheme' => $thesaurus,
            'schemeURI' => null,
            'description' => $description,
            'isLegacy' => true,
        ];
    }

    /**
     * Transform an array of MSL keywords from old database format to new format.
     *
     * @param  array<int, object>  $oldKeywords  Array of objects from old database
     * @return array<int, array<string, string|bool|null>> Array of transformed keywords
     */
    public static function transformMany(array $oldKeywords): array
    {
        $transformed = [];

        foreach ($oldKeywords as $oldKeyword) {
            $result = self::transform($oldKeyword);

            if ($result !== null) {
                $transformed[] = $result;
            }
        }

        return $transformed;
    }

    /**
     * Extract the last segment from a hierarchical path.
     *
     * @param  string  $path  Hierarchical path with " > " separator
     * @return string Last segment of the path
     */
    private static function extractLastPathSegment(string $path): string
    {
        $segments = array_map('trim', explode(' > ', $path));

        // explode() always returns at least one element, so array_last() never returns null here
        /** @var string */
        return array_last($segments);
    }

    /**
     * Get list of supported MSL thesaurus names from old database.
     *
     * @return array<int, string>
     */
    public static function getSupportedThesauri(): array
    {
        return LegacyMslScheme::schemes();
    }
}
