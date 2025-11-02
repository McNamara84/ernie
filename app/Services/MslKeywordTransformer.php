<?php

namespace App\Services;

class MslKeywordTransformer
{
    /**
     * Map old MSL thesaurus names to categories in the new vocabulary.
     *
     * Old format: "EPOS WP16 Analogue Material", "EPOS WP16 Analogue Apparatus", etc.
     */
    private const THESAURUS_CATEGORY_MAP = [
        'EPOS WP16 Analogue Material' => 'Material',
        'EPOS WP16 Analogue Apparatus' => 'Apparatus',
        'EPOS WP16 Analogue Monitoring' => 'Monitoring',
        'EPOS WP16 Analogue Software' => 'Software',
        'EPOS WP16 Analogue Measured Property' => 'Measured Property',
        'EPOS WP16 Analogue Main Setting' => 'Main Setting',
        'EPOS WP16 Analogue Geologic Feature' => 'Geologic Feature',
        'EPOS WP16 Analogue Geologic Structure' => 'Geologic Structure',
        'EPOS WP16 Analogue Process/Hazard' => 'Process',
        'EPOS WP16 Rock Physics Material' => 'Material',
        'EPOS WP16 Rock Physics Apparatus' => 'Apparatus',
        'EPOS WP16 Rock Physics Monitoring' => 'Monitoring',
        'EPOS WP16 Rock Physics Software' => 'Software',
        'EPOS WP16 Rock Physics Measured Property' => 'Measured Property',
        'EPOS WP16 Rock Physics Main Setting' => 'Main Setting',
        'EPOS WP16 Rock Physics Geologic Feature' => 'Geologic Feature',
        'EPOS WP16 Rock Physics Geologic Structure' => 'Geologic Structure',
        'EPOS WP16 Rock Physics Process/Hazard' => 'Process',
    ];

    /**
     * MSL Vocabulary scheme constants
     */
    private const MSL_SCHEME = 'EPOS MSL vocabulary';

    private const MSL_SCHEME_URI = 'https://epos-msl.uu.nl/voc';

    /**
     * Transform an MSL keyword from old database format to new format.
     *
     * @param  object  $oldKeyword  Object with properties: keyword, thesaurus, uri, description
     * @return array<string, string>|null Array with keys: id, text, path, language, scheme, schemeURI, description
     */
    public static function transform(object $oldKeyword): ?array
    {
        $thesaurus = $oldKeyword->thesaurus ?? '';
        $keyword = $oldKeyword->keyword ?? '';
        $oldUri = $oldKeyword->uri ?? '';
        $description = $oldKeyword->description ?? null;

        // Only process EPOS WP16 keywords
        if (! str_starts_with($thesaurus, 'EPOS WP16')) {
            return null;
        }

        // Extract category from thesaurus name
        $category = self::THESAURUS_CATEGORY_MAP[$thesaurus] ?? null;

        if (! $category) {
            // Unknown thesaurus, skip
            return null;
        }

        // Convert old URI to new URI format
        // Old: http://epos/WP16Vocabulary/AnalogueMaterial/Sand/Quartz
        // New: https://epos-msl.uu.nl/voc/materials/1.3/sand-quartz_sand
        $newUri = self::convertOldUriToNew($oldUri, $keyword, $category);

        // The keyword field contains the hierarchical path with " > " separator
        // e.g., "Sand > Quartz Sand"
        $path = $keyword;

        // Extract the last segment as text
        $text = self::extractLastPathSegment($path);

        return [
            'id' => $newUri,
            'text' => $text,
            'path' => $path,
            'language' => 'en',
            'scheme' => self::MSL_SCHEME,
            'schemeURI' => self::MSL_SCHEME_URI,
            'description' => $description,
        ];
    }

    /**
     * Transform an array of MSL keywords from old database format to new format.
     *
     * @param  array<int, object>  $oldKeywords  Array of objects from old database
     * @return array<int, array<string, string>> Array of transformed keywords
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
     * Convert old MSL URI to new vocabulary URI format.
     *
     * This is a best-effort conversion. If the old URI is empty or cannot be converted,
     * we construct a synthetic URI based on the keyword path.
     *
     * @param  string  $oldUri  Old URI (e.g., "http://epos/WP16Vocabulary/AnalogueMaterial/Sand/Quartz")
     * @param  string  $keyword  Hierarchical path (e.g., "Sand > Quartz Sand")
     * @param  string  $category  Category from thesaurus mapping
     * @return string New MSL vocabulary URI
     */
    private static function convertOldUriToNew(string $oldUri, string $keyword, string $category): string
    {
        // If we have an old URI, try to extract the path components
        if (! empty($oldUri) && str_contains($oldUri, '/')) {
            // Extract path after WP16Vocabulary/
            if (preg_match('#/WP16Vocabulary/[^/]+/(.+)$#', $oldUri, $matches)) {
                $pathSegments = explode('/', $matches[1]);
                $slug = strtolower(implode('-', $pathSegments));

                // Map category to vocabulary path
                $vocabPath = self::mapCategoryToVocabPath($category);

                return self::MSL_SCHEME_URI.'/'.$vocabPath.'/1.3/'.$slug;
            }
        }

        // Fallback: construct URI from keyword path
        // "Sand > Quartz Sand" -> "sand-quartz_sand"
        $pathSegments = array_map('trim', explode(' > ', $keyword));
        $slug = strtolower(implode('-', $pathSegments));
        $slug = str_replace(' ', '_', $slug);

        $vocabPath = self::mapCategoryToVocabPath($category);

        return self::MSL_SCHEME_URI.'/'.$vocabPath.'/1.3/'.$slug;
    }

    /**
     * Map category to vocabulary path segment.
     *
     * @param  string  $category  Category name
     * @return string Vocabulary path segment
     */
    private static function mapCategoryToVocabPath(string $category): string
    {
        $pathMap = [
            'Material' => 'materials',
            'Apparatus' => 'apparatus',
            'Monitoring' => 'monitoring',
            'Software' => 'software',
            'Measured Property' => 'measured-properties',
            'Main Setting' => 'main-settings',
            'Geologic Feature' => 'geologic-features',
            'Geologic Structure' => 'geologic-structures',
            'Process' => 'processes',
        ];

        return $pathMap[$category] ?? 'unknown';
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

        return end($segments) ?: $path;
    }

    /**
     * Get list of supported MSL thesaurus names from old database.
     *
     * @return array<int, string>
     */
    public static function getSupportedThesauri(): array
    {
        return array_keys(self::THESAURUS_CATEGORY_MAP);
    }
}
