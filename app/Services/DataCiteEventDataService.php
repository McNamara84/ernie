<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for querying the DataCite Event Data API.
 *
 * Used as a supplementary/fallback source for discovering scholarly
 * relations when ScholExplorer does not return results.
 *
 * @see https://support.datacite.org/docs/eventdata-guide
 * @see https://api.datacite.org/events
 */
class DataCiteEventDataService
{
    /**
     * Mapping from DataCite Event Data kebab-case relation types to PascalCase DataCite slugs.
     *
     * @var array<string, string>
     */
    private const RELATION_TYPE_MAP = [
        'is-cited-by' => 'IsCitedBy',
        'cites' => 'Cites',
        'is-supplement-to' => 'IsSupplementTo',
        'is-supplemented-by' => 'IsSupplementedBy',
        'is-referenced-by' => 'IsReferencedBy',
        'references' => 'References',
        'is-part-of' => 'IsPartOf',
        'has-part' => 'HasPart',
        'is-version-of' => 'IsVersionOf',
        'has-version' => 'HasVersion',
        'is-identical-to' => 'IsIdenticalTo',
        'is-derived-from' => 'IsDerivedFrom',
        'is-source-of' => 'IsSourceOf',
        'is-new-version-of' => 'IsNewVersionOf',
        'is-previous-version-of' => 'IsPreviousVersionOf',
        'is-continued-by' => 'IsContinuedBy',
        'continues' => 'Continues',
        'is-described-by' => 'IsDescribedBy',
        'describes' => 'Describes',
        'has-metadata' => 'HasMetadata',
        'is-metadata-for' => 'IsMetadataFor',
        'is-reviewed-by' => 'IsReviewedBy',
        'reviews' => 'Reviews',
        'is-documented-by' => 'IsDocumentedBy',
        'documents' => 'Documents',
        'is-compiled-by' => 'IsCompiledBy',
        'compiles' => 'Compiles',
        'is-variant-form-of' => 'IsVariantFormOf',
        'is-original-form-of' => 'IsOriginalFormOf',
        'is-required-by' => 'IsRequiredBy',
        'requires' => 'Requires',
        'is-obsoleted-by' => 'IsObsoletedBy',
        'obsoletes' => 'Obsoletes',
        'is-collected-by' => 'IsCollectedBy',
        'collects' => 'Collects',
        'is-published-in' => 'IsPublishedIn',
        'has-translation' => 'HasTranslation',
        'is-translation-of' => 'IsTranslationOf',
    ];

    /**
     * Source IDs to include (scholarly relations only).
     * Excludes datacite-usage and datacite-resolution which are not scholarly links.
     *
     * @var array<int, string>
     */
    private const SOURCE_IDS = [
        'datacite-crossref',
        'crossref',
        'datacite-datacite',
    ];

    /**
     * Find scholarly relations for a given DOI via DataCite Event Data API.
     *
     * @param  string  $doi  The DOI to search for (e.g. "10.5880/GFZ.2024.001")
     * @return array<int, array{identifier: string, identifier_type: string, relation_type: string, source_title: string|null, source_type: string|null, source_publisher: string|null, source_publication_date: string|null}>
     */
    public function findRelationsForDoi(string $doi): array
    {
        $relations = [];

        try {
            $response = Http::timeout(30)
                ->get('https://api.datacite.org/events', [
                    'doi' => $doi,
                    'page[size]' => 200,
                    'source-id' => implode(',', self::SOURCE_IDS),
                ]);

            if (! $response->successful()) {
                Log::warning('DataCite Event Data API returned non-success status', [
                    'doi' => $doi,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $events = $data['data'] ?? [];

            foreach ($events as $event) {
                $parsed = $this->parseEvent($event, $doi);
                if ($parsed !== null) {
                    $relations[] = $parsed;
                }
            }
        } catch (\Exception $e) {
            Log::error('DataCite Event Data API request failed', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);
        }

        return $relations;
    }

    /**
     * Parse a single event into a normalized relation array.
     *
     * @param  array<string, mixed>  $event
     * @return array{identifier: string, identifier_type: string, relation_type: string, source_title: string|null, source_type: string|null, source_publisher: string|null, source_publication_date: string|null}|null
     */
    private function parseEvent(array $event, string $queriedDoi): ?array
    {
        $attributes = $event['attributes'] ?? [];

        $subjId = $attributes['subj-id'] ?? null;
        $objId = $attributes['obj-id'] ?? null;
        $relationTypeId = $attributes['relation-type-id'] ?? null;

        if ($subjId === null || $objId === null || $relationTypeId === null) {
            return null;
        }

        // Determine which side is the "other" identifier
        $queriedDoiLower = mb_strtolower($queriedDoi);

        $subjDoi = $this->extractDoiFromUrl((string) $subjId);
        $objDoi = $this->extractDoiFromUrl((string) $objId);

        if ($subjDoi !== null && mb_strtolower($subjDoi) === $queriedDoiLower) {
            // Our DOI is the subject → the object is the related identifier
            $otherIdentifier = $objDoi ?? (string) $objId;
        } elseif ($objDoi !== null && mb_strtolower($objDoi) === $queriedDoiLower) {
            // Our DOI is the object → the subject is the related identifier
            $otherIdentifier = $subjDoi ?? (string) $subjId;
        } else {
            return null;
        }

        // Skip self-references
        if (mb_strtolower($otherIdentifier) === $queriedDoiLower) {
            return null;
        }

        // Map relation type
        $relationType = self::RELATION_TYPE_MAP[$relationTypeId] ?? null;
        if ($relationType === null) {
            return null;
        }

        // Determine identifier type
        $identifierType = $this->extractDoiFromUrl($otherIdentifier) !== null ? 'DOI' : 'URL';

        return [
            'identifier' => $otherIdentifier,
            'identifier_type' => $identifierType,
            'relation_type' => $relationType,
            // DataCite Event Data does not provide these metadata fields
            'source_title' => null,
            'source_type' => null,
            'source_publisher' => null,
            'source_publication_date' => null,
        ];
    }

    /**
     * Extract a DOI from a URL like "https://doi.org/10.xxxx/yyyy".
     */
    private function extractDoiFromUrl(string $url): ?string
    {
        if (preg_match('#^https?://(?:dx\.)?doi\.org/(.+)$#i', $url, $matches) === 1) {
            return $matches[1];
        }

        // Already a plain DOI (starts with 10.)
        if (str_starts_with($url, '10.')) {
            return $url;
        }

        return null;
    }
}
