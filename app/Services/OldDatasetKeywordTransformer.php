<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GcmdUriHelper;
use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;

class OldDatasetKeywordTransformer
{
    /**
     * Maps old thesaurus names to scheme names.
     * Supports legacy GEMET plus old and current NASA/GCMD naming conventions.
     */
    private const SCHEME_MAP = [
        // Science Keywords - single standard format
        'NASA/GCMD Earth Science Keywords' => 'Science Keywords',
        // Platforms - support both legacy and current ELMO formats
        'NASA/GCMD Earth Platforms Keywords' => 'Platforms',
        'NASA/GCMD Platforms Keywords' => 'Platforms',
        'GCMD Platforms' => 'Platforms',
        // Instruments - support both legacy and current ELMO formats
        'NASA/GCMD Instruments' => 'Instruments',
        'GCMD Instruments' => 'Instruments',
        // GEMET - legacy DataCite scheme title
        'GEMET - INSPIRE themes, version 1.0' => GemetVocabularyParser::SCHEME_TITLE,
        'CGI Simple Lithology' => PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY,
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
     * @return array<string, string|bool|null>|null Array with keys: id, text, scheme, schemeURI, path, uuid, description, isLegacy
     */
    public static function transform(object $oldKeyword): ?array
    {
        // Map to scheme name
        $scheme = self::mapScheme($oldKeyword->thesaurus ?? '');

        if (! $scheme) {
            return null;
        }

        $keyword = trim((string) ($oldKeyword->keyword ?? ''));
        $normalizedKeyword = PortalSubjectNormalizer::normalizeControlledSubjectValue($keyword) ?? $keyword;
        $outputText = $normalizedKeyword;
        $outputPath = $normalizedKeyword;
        $schemeUri = self::schemeUriForScheme($scheme);

        if ($scheme === GemetVocabularyParser::SCHEME_TITLE) {
            $uuid = null;
            $newUri = self::normalizeGemetConceptUri($oldKeyword->uri ?? null);
        } elseif ($scheme === PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY) {
            $uuid = null;
            $newUri = self::normalizeSimpleLithologyConceptUri($oldKeyword->uri ?? null);
        } else {
            // Extract UUID from old GCMD URI
            $uuid = self::extractUuidFromOldUri($oldKeyword->uri ?? null);
            $newUri = $uuid ? self::constructNewUri($uuid) : null;
        }

        $resolvedKeyword = $normalizedKeyword !== ''
            ? app(SubjectBreadcrumbPathResolverService::class)->resolveKeywordFromPath($scheme, $normalizedKeyword)
            : null;

        if ($resolvedKeyword !== null
            && ($newUri === null || hash_equals(mb_strtolower($newUri), mb_strtolower($resolvedKeyword['id'])))
        ) {
            $outputText = $resolvedKeyword['text'];
            $outputPath = $resolvedKeyword['path'];

            if ($newUri === null) {
                $newUri = $resolvedKeyword['id'];
                $uuid = in_array($scheme, [
                    GemetVocabularyParser::SCHEME_TITLE,
                    PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY,
                ], true)
                    ? null
                    : self::extractUuidFromOldUri($newUri);
                $scheme = $resolvedKeyword['scheme'];
                $schemeUri = $resolvedKeyword['schemeURI'] ?? self::schemeUriForScheme($scheme);
            }
        }

        if ($newUri === null && $scheme === PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY && $normalizedKeyword !== '') {
            $newUri = 'legacy:'.hash('sha256', mb_strtolower($scheme.'|'.$normalizedKeyword));
        }

        if ($newUri === null) {
            return null;
        }

        return [
            'id' => $newUri,
            'text' => $outputText,
            'scheme' => $scheme,
            'schemeURI' => $schemeUri,
            'path' => $outputPath,
            'uuid' => $uuid,
            'description' => $oldKeyword->description ?? null,
            ...(! filter_var($newUri, FILTER_VALIDATE_URL) ? ['isLegacy' => true] : []),
        ];
    }

    /**
     * Transform an array of keywords from old database format to new format.
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
     * Get list of supported controlled-vocabulary names from the old database.
     *
     * @return array<int, string>
     */
    public static function getSupportedThesauri(): array
    {
        return array_keys(self::SCHEME_MAP);
    }

    private static function schemeUriForScheme(string $scheme): ?string
    {
        return app(SubjectBreadcrumbPathResolverService::class)->resolveSchemeUri($scheme);
    }

    private static function normalizeGemetConceptUri(mixed $uri): ?string
    {
        if (! is_string($uri)) {
            return null;
        }

        if (preg_match(
            '~^https?://(?:www\.)?eionet\.europa\.eu/gemet/concept/([^/?#\s]+)/?(?:[?#].*)?$~i',
            trim($uri),
            $matches,
        ) !== 1) {
            return null;
        }

        return 'http://www.eionet.europa.eu/gemet/concept/'.$matches[1];
    }

    private static function normalizeSimpleLithologyConceptUri(mixed $uri): ?string
    {
        if (! is_string($uri)) {
            return null;
        }

        if (preg_match(
            '~^https?://resource\.geosciml\.org/classifier/cgi/lithology/([^?#\s]+)/?(?:[?#].*)?$~i',
            trim($uri),
            $matches,
        ) !== 1) {
            return null;
        }

        return rtrim((string) config('simple_lithology.collection_uri'), '/').'/'.trim($matches[1], '/');
    }
}
