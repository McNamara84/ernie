<?php

namespace App\Services;

class OldDatasetKeywordTransformer
{
    /**
     * Maps old thesaurus names to new vocabulary types.
     */
    private const VOCABULARY_TYPE_MAP = [
        'NASA/GCMD Earth Science Keywords' => 'gcmd-science-keywords',
        'GCMD Platforms' => 'gcmd-platforms',
        'GCMD Instruments' => 'gcmd-instruments',
    ];

    /**
     * Pattern for extracting UUID from old URI format.
     * Old format: http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/{uuid}
     */
    private const UUID_PATTERN = '/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i';

    /**
     * New URI template for GCMD concepts.
     * New format: https://gcmd.earthdata.nasa.gov/kms/concept/{uuid}
     */
    private const NEW_URI_TEMPLATE = 'https://gcmd.earthdata.nasa.gov/kms/concept/%s';

    /**
     * Extract UUID from old GCMD URI format.
     *
     * @param string|null $oldUri
     * @return string|null
     */
    public static function extractUuidFromOldUri(?string $oldUri): ?string
    {
        if (!$oldUri) {
            return null;
        }

        if (preg_match(self::UUID_PATTERN, $oldUri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Construct new GCMD URI from UUID.
     *
     * @param string $uuid
     * @return string
     */
    public static function constructNewUri(string $uuid): string
    {
        return sprintf(self::NEW_URI_TEMPLATE, $uuid);
    }

    /**
     * Map old thesaurus name to new vocabulary type.
     *
     * @param string $thesaurusName
     * @return string|null
     */
    public static function mapVocabularyType(string $thesaurusName): ?string
    {
        return self::VOCABULARY_TYPE_MAP[$thesaurusName] ?? null;
    }

    /**
     * Transform a keyword from old database format to new format.
     *
     * @param object $oldKeyword Object with properties: keyword, thesaurus, uri, description
     * @return array|null Array with keys: id, text, vocabulary, path, uuid, description
     */
    public static function transform(object $oldKeyword): ?array
    {
        // Extract UUID from old URI
        $uuid = self::extractUuidFromOldUri($oldKeyword->uri ?? null);
        
        if (!$uuid) {
            return null;
        }

        // Map vocabulary type
        $vocabularyType = self::mapVocabularyType($oldKeyword->thesaurus ?? '');
        
        if (!$vocabularyType) {
            return null;
        }

        // Construct new URI
        $newUri = self::constructNewUri($uuid);

        return [
            'id' => $newUri,
            'text' => $oldKeyword->keyword ?? '',
            'vocabulary' => $vocabularyType,
            'path' => $oldKeyword->keyword ?? '', // The keyword text IS the hierarchical path
            'uuid' => $uuid,
            'description' => $oldKeyword->description ?? null,
        ];
    }

    /**
     * Transform an array of keywords from old database format to new format.
     *
     * @param array $oldKeywords Array of objects from old database
     * @return array Array of transformed keywords
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
     * @return array
     */
    public static function getSupportedThesauri(): array
    {
        return array_keys(self::VOCABULARY_TYPE_MAP);
    }
}
