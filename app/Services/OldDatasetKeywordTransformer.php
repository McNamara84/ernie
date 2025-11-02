<?php

namespace App\Services;

use App\Support\GcmdUriHelper;

class OldDatasetKeywordTransformer
{
    /**
     * Maps old thesaurus names to scheme names.
     */
    private const SCHEME_MAP = [
        'NASA/GCMD Earth Science Keywords' => 'Science Keywords',
        'GCMD Platforms' => 'Platforms',
        'GCMD Instruments' => 'Instruments',
    ];

    /**
     * Extract UUID from old GCMD URI format.
     *
     * @deprecated Use GcmdUriHelper::extractUuid() instead
     */
    public static function extractUuidFromOldUri(?string $oldUri): ?string
    {
        return GcmdUriHelper::extractUuid($oldUri);
    }

    /**
     * Construct new GCMD URI from UUID.
     *
     * @deprecated Use GcmdUriHelper::buildConceptUri() instead
     */
    public static function constructNewUri(string $uuid): string
    {
        return GcmdUriHelper::buildConceptUri($uuid);
    }

    /**
     * Map old thesaurus name to scheme name.
     */
    public static function mapScheme(string $thesaurusName): ?string
    {
        return self::SCHEME_MAP[$thesaurusName] ?? null;
    }

    /**
     * Transform a keyword from old database format to new format.
     *
     * @param  object  $oldKeyword  Object with properties: keyword, thesaurus, uri, description
     * @return array<string, string|null>|null Array with keys: id, text, scheme, path, uuid, description
     */
    public static function transform(object $oldKeyword): ?array
    {
        // Extract UUID from old URI
        $uuid = self::extractUuidFromOldUri($oldKeyword->uri ?? null);

        if (! $uuid) {
            return null;
        }

        // Map to scheme name
        $scheme = self::mapScheme($oldKeyword->thesaurus ?? '');

        if (! $scheme) {
            return null;
        }

        // Construct new URI
        $newUri = self::constructNewUri($uuid);

        return [
            'id' => $newUri,
            'text' => $oldKeyword->keyword ?? '',
            'scheme' => $scheme,
            'path' => $oldKeyword->keyword ?? '', // The keyword text IS the hierarchical path
            'uuid' => $uuid,
            'description' => $oldKeyword->description ?? null,
        ];
    }

    /**
     * Transform an array of keywords from old database format to new format.
     *
     * @param  array<int, object>  $oldKeywords  Array of objects from old database
     * @return array<int, array<string, string|null>> Array of transformed keywords
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
     * Get list of supported GCMD thesaurus names from old database.
     *
     * @return array<int, string>
     */
    public static function getSupportedThesauri(): array
    {
        return array_keys(self::SCHEME_MAP);
    }
}
