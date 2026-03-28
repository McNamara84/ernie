<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for querying the OpenAIRE ScholExplorer API.
 *
 * Discovers scholarly links (citations, references, supplements, etc.)
 * between datasets and publications using the Scholix format.
 *
 * @see https://scholexplorer.openaire.eu/
 * @see https://api.scholexplorer.openaire.eu/v3/ui
 */
class ScholExplorerService
{
    /**
     * Mapping from Scholix relationship names to DataCite relationType slugs.
     *
     * @var array<string, string>
     */
    private const RELATION_TYPE_MAP = [
        'cites' => 'Cites',
        'is-cited-by' => 'IsCitedBy',
        'iscitedby' => 'IsCitedBy',
        'references' => 'References',
        'is-referenced-by' => 'IsReferencedBy',
        'isreferencedby' => 'IsReferencedBy',
        'documents' => 'Documents',
        'is-documented-by' => 'IsDocumentedBy',
        'isdocumentedby' => 'IsDocumentedBy',
        'reviews' => 'Reviews',
        'is-reviewed-by' => 'IsReviewedBy',
        'isreviewedby' => 'IsReviewedBy',
        'is-supplement-to' => 'IsSupplementTo',
        'issupplementto' => 'IsSupplementTo',
        'is-supplemented-by' => 'IsSupplementedBy',
        'issupplementedby' => 'IsSupplementedBy',
        'is-part-of' => 'IsPartOf',
        'ispartof' => 'IsPartOf',
        'has-part' => 'HasPart',
        'haspart' => 'HasPart',
        'is-version-of' => 'IsVersionOf',
        'isversionof' => 'IsVersionOf',
        'has-version' => 'HasVersion',
        'hasversion' => 'HasVersion',
        'is-derived-from' => 'IsDerivedFrom',
        'isderivedfrom' => 'IsDerivedFrom',
        'is-source-of' => 'IsSourceOf',
        'issourceof' => 'IsSourceOf',
        'is-identical-to' => 'IsIdenticalTo',
        'isidenticalto' => 'IsIdenticalTo',
        'continues' => 'Continues',
        'is-continued-by' => 'IsContinuedBy',
        'iscontinuedby' => 'IsContinuedBy',
        'compiles' => 'Compiles',
        'is-compiled-by' => 'IsCompiledBy',
        'iscompiledby' => 'IsCompiledBy',
        'is-required-by' => 'IsRequiredBy',
        'isrequiredby' => 'IsRequiredBy',
        'requires' => 'Requires',
        'is-obsoleted-by' => 'IsObsoletedBy',
        'isobsoletedby' => 'IsObsoletedBy',
        'obsoletes' => 'Obsoletes',
        'collects' => 'Collects',
        'is-collected-by' => 'IsCollectedBy',
        'iscollectedby' => 'IsCollectedBy',
    ];

    /**
     * Find scholarly relations for a given DOI.
     *
     * @param  string  $doi  The DOI to search for (e.g. "10.5880/GFZ.2024.001")
     * @return array<int, array{identifier: string, identifier_type: string, relation_type: string, source_title: string|null, source_type: string|null, source_publisher: string|null, source_publication_date: string|null}>
     */
    public function findRelationsForDoi(string $doi): array
    {
        $baseUrl = config('scholexplorer.base_url', 'https://api.scholexplorer.openaire.eu/v3');
        $timeout = config('scholexplorer.timeout', 30);

        $relations = [];
        $page = 0;
        $maxPages = 10; // Safety limit

        try {
            do {
                /** @var string $baseUrl */
                $response = Http::timeout((int) $timeout)
                    ->get($baseUrl . '/Links', [
                        'sourcePid' => $doi,
                        'page' => $page,
                        'size' => 100,
                    ]);

                if (! $response->successful()) {
                    Log::warning('ScholExplorer API returned non-success status', [
                        'doi' => $doi,
                        'status' => $response->status(),
                        'page' => $page,
                    ]);
                    break;
                }

                $data = $response->json();

                if (! is_array($data) || ! isset($data['result'])) {
                    Log::warning('ScholExplorer API returned unexpected response format', [
                        'doi' => $doi,
                        'page' => $page,
                    ]);
                    break;
                }

                $results = $data['result'];
                $totalPages = $data['totalPages'] ?? 1;

                foreach ($results as $link) {
                    $parsed = $this->parseLink($link, $doi);
                    if ($parsed !== null) {
                        $relations[] = $parsed;
                    }
                }

                $page++;
            } while ($page < $totalPages && $page < $maxPages);
        } catch (\Throwable $e) {
            Log::error('ScholExplorer API request failed', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);
        }

        return $relations;
    }

    /**
     * Parse a single Scholix link into a normalized relation array.
     *
     * @param  array<string, mixed>  $link
     * @return array{identifier: string, identifier_type: string, relation_type: string, source_title: string|null, source_type: string|null, source_publisher: string|null, source_publication_date: string|null}|null
     */
    private function parseLink(array $link, string $queriedDoi): ?array
    {
        // Determine which side is the "other" identifier
        $target = $link['target'] ?? null;
        if ($target === null) {
            return null;
        }

        $identifiers = $target['Identifier'] ?? [];
        if (empty($identifiers)) {
            return null;
        }

        $identifier = $identifiers[0]['ID'] ?? null;
        $idScheme = $identifiers[0]['IDScheme'] ?? 'DOI';

        if ($identifier === null || $identifier === '') {
            return null;
        }

        // Skip self-references
        if (mb_strtolower($identifier) === mb_strtolower($queriedDoi)) {
            return null;
        }

        // Map the relationship type
        $relationshipType = $link['RelationshipType'] ?? [];
        $relationName = mb_strtolower((string) ($relationshipType['Name'] ?? ''));
        $relationType = self::RELATION_TYPE_MAP[$relationName] ?? null;

        if ($relationType === null) {
            Log::info('ScholExplorer: skipping unknown relationship type', [
                'relationship_name' => $relationName,
                'identifier' => $identifier,
            ]);

            return null;
        }

        // Map identifier scheme
        $identifierType = $this->mapIdentifierType($idScheme);

        // Extract metadata
        $title = $target['Title'] ?? null;
        $type = $target['Type'] ?? null;

        $publishers = $target['Publisher'] ?? [];
        $publisher = ! empty($publishers) ? ($publishers[0]['name'] ?? null) : null;

        $publicationDate = $target['PublicationDate'] ?? null;

        return [
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'relation_type' => $relationType,
            'source_title' => $title,
            'source_type' => $type,
            'source_publisher' => $publisher,
            'source_publication_date' => $publicationDate,
        ];
    }

    /**
     * Map ScholExplorer identifier scheme to DataCite identifier type slug.
     */
    private function mapIdentifierType(string $scheme): string
    {
        return match (mb_strtoupper($scheme)) {
            'DOI' => 'DOI',
            'PMID' => 'PMID',
            'ARXIV' => 'arXiv',
            'URL' => 'URL',
            'URN' => 'URN',
            'HANDLE' => 'Handle',
            'ISBN' => 'ISBN',
            'ISSN' => 'ISSN',
            default => 'URL',
        };
    }
}
