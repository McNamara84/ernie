<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\Log;

class RelatedIdentifierCitationLabelService
{
    public const DEFAULT_AGGREGATE_TIMEOUT_SECONDS = 2.0;

    public const DEFAULT_PER_REQUEST_TIMEOUT_SECONDS = 0.75;

    private const MIN_REQUEST_TIMEOUT_SECONDS = 0.1;

    public function __construct(
        private readonly DataCiteApiService $dataCite,
        private readonly LegacyCitationCacheService $legacyCitationCache,
    ) {}

    public function resolve(string $identifier, string $identifierType, ?float $timeoutSeconds = null): ?string
    {
        $identifier = trim($identifier);
        $identifierType = trim($identifierType);

        $legacyUrlCitation = $this->resolveFromLiteralUrlCache($identifier, $identifierType);

        if ($legacyUrlCitation !== null) {
            return $legacyUrlCitation;
        }

        $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

        if ($doi === null) {
            return null;
        }

        return $this->resolveNormalizedDoi($doi, $timeoutSeconds);
    }

    public function resolveBestEffort(string $identifier, string $identifierType, float $deadline): ?string
    {
        $identifier = trim($identifier);
        $identifierType = trim($identifierType);

        $legacyUrlCitation = $this->resolveFromLiteralUrlCache($identifier, $identifierType);

        if ($legacyUrlCitation !== null) {
            return $legacyUrlCitation;
        }

        $remainingBudget = $deadline - microtime(true);

        if ($remainingBudget <= 0) {
            return null;
        }

        $timeoutSeconds = min(self::DEFAULT_PER_REQUEST_TIMEOUT_SECONDS, $remainingBudget);

        if ($timeoutSeconds < self::MIN_REQUEST_TIMEOUT_SECONDS) {
            return null;
        }

        $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

        if ($doi === null) {
            return null;
        }

        return $this->resolveNormalizedDoi($doi, $timeoutSeconds);
    }

    /**
     * Resolve a complete import list within one shared best-effort deadline.
     *
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array<int, array<string, mixed>>
     */
    public function resolveBestEffortBatch(array $relatedIdentifiers, float $deadline): array
    {
        /** @var array<string, list<int>> $indexesByUrl */
        $indexesByUrl = [];
        /** @var array<string, list<int>> $indexesByDoi */
        $indexesByDoi = [];

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            $identifier = trim((string) ($relatedIdentifier['relatedIdentifier'] ?? ''));
            $identifierType = trim((string) ($relatedIdentifier['relatedIdentifierType'] ?? ''));
            $citationLabel = trim((string) ($relatedIdentifier['citationLabel'] ?? ''));

            if ($identifier === '') {
                unset($relatedIdentifiers[$index]['citationLabel']);

                continue;
            }

            $relatedIdentifiers[$index]['relatedIdentifier'] = $identifier;

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;

                continue;
            }

            if ($identifierType === 'URL') {
                $indexesByUrl[$identifier] ??= [];
                $indexesByUrl[$identifier][] = $index;
            }
        }

        if ($indexesByUrl !== []) {
            $legacyUrlCitations = $this->legacyCitationCache->findManyUrls(array_keys($indexesByUrl));

            foreach ($legacyUrlCitations as $url => $citation) {
                if (! isset($indexesByUrl[$url]) || trim($citation) === '') {
                    continue;
                }

                foreach ($indexesByUrl[$url] as $index) {
                    $relatedIdentifiers[$index]['citationLabel'] = trim($citation);
                }
            }
        }

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            $citationLabel = trim((string) ($relatedIdentifier['citationLabel'] ?? ''));

            if ($citationLabel !== '') {
                continue;
            }

            $identifier = trim((string) ($relatedIdentifier['relatedIdentifier'] ?? ''));
            $identifierType = trim((string) ($relatedIdentifier['relatedIdentifierType'] ?? ''));

            $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

            if ($doi !== null) {
                $indexesByDoi[$doi] ??= [];
                $indexesByDoi[$doi][] = $index;
            }
        }

        if ($indexesByDoi === []) {
            return $relatedIdentifiers;
        }

        $legacyCitations = $this->legacyCitationCache->findMany(array_keys($indexesByDoi));

        foreach ($legacyCitations as $doi => $citation) {
            if (! isset($indexesByDoi[$doi]) || trim($citation) === '') {
                continue;
            }

            foreach ($indexesByDoi[$doi] as $index) {
                $relatedIdentifiers[$index]['citationLabel'] = trim($citation);
            }

            unset($indexesByDoi[$doi]);
        }

        foreach ($indexesByDoi as $doi => $indexes) {
            $remainingBudget = $deadline - microtime(true);

            if ($remainingBudget < self::MIN_REQUEST_TIMEOUT_SECONDS) {
                break;
            }

            $citation = $this->resolveFromDoiMetadata(
                $doi,
                min(self::DEFAULT_PER_REQUEST_TIMEOUT_SECONDS, $remainingBudget),
            );

            if ($citation === null) {
                continue;
            }

            foreach ($indexes as $index) {
                $relatedIdentifiers[$index]['citationLabel'] = $citation;
            }
        }

        return $relatedIdentifiers;
    }

    /**
     * Resolve the editor/storage representation within one shared best-effort deadline.
     *
     * @param  array<int, mixed>  $relatedIdentifiers
     * @return array<int, mixed>
     */
    public function resolveBestEffortBatchForStorage(array $relatedIdentifiers, float $deadline): array
    {
        return $this->resolveForStorage(
            $relatedIdentifiers,
            fn (array $candidates): array => $this->resolveBestEffortBatch($candidates, $deadline),
        );
    }

    /**
     * Exhaustively resolve the editor/storage representation of related identifiers.
     *
     * The exhaustive resolver operates on an entire list so DOI metadata can be
     * fetched through the retrying batch path. This adapter preserves the storage
     * payload's field names while delegating resolution to {@see resolveExhaustive()}.
     *
     * @param  array<int, mixed>  $relatedIdentifiers
     * @return array<int, mixed>
     */
    public function resolveExhaustiveForStorage(array $relatedIdentifiers): array
    {
        return $this->resolveForStorage(
            $relatedIdentifiers,
            $this->resolveExhaustive(...),
        );
    }

    /**
     * Exhaustively resolve missing citation labels for a single-resource import.
     *
     * Existing labels are preserved. DOI-like URL identifiers participate in
     * resolution, while unrelated URL and non-DOI identifier types stay optional.
     * A final miss is retained without a label and never blocks the import.
     *
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array<int, array<string, mixed>>
     */
    public function resolveExhaustive(array $relatedIdentifiers): array
    {
        /** @var array<string, list<int>> $indexesByUrl */
        $indexesByUrl = [];
        /** @var array<string, list<int>> $indexesByDoi */
        $indexesByDoi = [];
        $existingLabelCount = 0;

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            $identifier = isset($relatedIdentifier['relatedIdentifier'])
                ? trim((string) $relatedIdentifier['relatedIdentifier'])
                : '';
            $identifierType = isset($relatedIdentifier['relatedIdentifierType'])
                ? trim((string) $relatedIdentifier['relatedIdentifierType'])
                : '';
            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            if ($identifier !== '') {
                $relatedIdentifiers[$index]['relatedIdentifier'] = $identifier;
            }

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;
                $existingLabelCount++;

                continue;
            }

            if ($identifierType === 'URL' && $identifier !== '') {
                $indexesByUrl[$identifier] ??= [];
                $indexesByUrl[$identifier][] = $index;
            }
        }

        $legacyUrlHitCount = 0;

        if ($indexesByUrl !== []) {
            $legacyUrlCitations = $this->legacyCitationCache->findManyUrls(array_keys($indexesByUrl));

            foreach ($legacyUrlCitations as $url => $citation) {
                if (! isset($indexesByUrl[$url]) || trim($citation) === '') {
                    continue;
                }

                foreach ($indexesByUrl[$url] as $index) {
                    $relatedIdentifiers[$index]['citationLabel'] = trim($citation);
                }

                $legacyUrlHitCount++;
            }
        }

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            if ($citationLabel !== '') {
                continue;
            }

            $identifier = isset($relatedIdentifier['relatedIdentifier'])
                ? trim((string) $relatedIdentifier['relatedIdentifier'])
                : '';
            $identifierType = isset($relatedIdentifier['relatedIdentifierType'])
                ? trim((string) $relatedIdentifier['relatedIdentifierType'])
                : '';

            $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

            if ($doi === null) {
                continue;
            }

            $indexesByDoi[$doi] ??= [];
            $indexesByDoi[$doi][] = $index;
        }

        $legacyDoiHitCount = 0;
        $metadataHitCount = 0;
        /** @var array<string, string> $unresolved */
        $unresolved = [];

        if ($indexesByDoi !== []) {
            $legacyCitations = $this->legacyCitationCache->findMany(array_keys($indexesByDoi));

            foreach ($legacyCitations as $doi => $citation) {
                if (! isset($indexesByDoi[$doi]) || trim($citation) === '') {
                    continue;
                }

                foreach ($indexesByDoi[$doi] as $index) {
                    $relatedIdentifiers[$index]['citationLabel'] = trim($citation);
                }

                unset($indexesByDoi[$doi]);
                $legacyDoiHitCount++;
            }
        }

        if ($indexesByDoi !== []) {
            $outcomes = $this->dataCite->getMetadataBatch(array_keys($indexesByDoi));

            foreach ($indexesByDoi as $doi => $indexes) {
                $outcome = $outcomes[$doi] ?? null;

                if (! is_array($outcome)) {
                    $unresolved[$doi] = 'The DOI metadata service returned no result.';

                    continue;
                }

                if ($outcome['status'] !== 'resolved') {
                    $unresolved[$doi] = $outcome['reason'];

                    continue;
                }

                $citation = trim($this->dataCite->buildCitationFromMetadata($outcome['metadata']));

                if ($citation === '') {
                    $unresolved[$doi] = 'The DOI metadata did not produce a citation label.';

                    continue;
                }

                foreach ($indexes as $index) {
                    $relatedIdentifiers[$index]['citationLabel'] = $citation;
                }

                $metadataHitCount++;
            }
        }

        Log::info('Exhaustive citation label resolution completed.', [
            'existing_labels' => $existingLabelCount,
            'legacy_hits' => $legacyUrlHitCount + $legacyDoiHitCount,
            'legacy_url_hits' => $legacyUrlHitCount,
            'legacy_doi_hits' => $legacyDoiHitCount,
            'doi_metadata_hits' => $metadataHitCount,
            'unresolved_count' => count($unresolved),
            'unresolved' => $unresolved,
        ]);

        return $relatedIdentifiers;
    }

    /**
     * @param  array<int, mixed>  $relatedIdentifiers
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $resolver
     * @return array<int, mixed>
     */
    private function resolveForStorage(array $relatedIdentifiers, callable $resolver): array
    {
        $resolutionCandidates = [];

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $identifier = isset($relatedIdentifier['identifier'])
                ? trim((string) $relatedIdentifier['identifier'])
                : '';
            $identifierType = isset($relatedIdentifier['identifierType'])
                ? trim((string) $relatedIdentifier['identifierType'])
                : '';
            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            if ($identifier === '') {
                unset($relatedIdentifiers[$index]['citationLabel']);

                continue;
            }

            $relatedIdentifiers[$index]['identifier'] = $identifier;

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;
            } else {
                unset($relatedIdentifiers[$index]['citationLabel']);
            }

            $resolutionCandidates[$index] = [
                'relatedIdentifier' => $identifier,
                'relatedIdentifierType' => $identifierType,
            ];

            if ($citationLabel !== '') {
                $resolutionCandidates[$index]['citationLabel'] = $citationLabel;
            }
        }

        $resolvedCandidates = $resolver($resolutionCandidates);

        foreach ($resolvedCandidates as $index => $resolvedCandidate) {
            $citationLabel = isset($resolvedCandidate['citationLabel'])
                ? trim((string) $resolvedCandidate['citationLabel'])
                : '';

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;
            } else {
                unset($relatedIdentifiers[$index]['citationLabel']);
            }
        }

        return $relatedIdentifiers;
    }

    public function normalizeResolvableDoi(string $identifier, string $identifierType): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $normalizedType = trim($identifierType);
        if ($normalizedType !== 'DOI' && $normalizedType !== 'URL') {
            return null;
        }

        $identifier = preg_replace('/^doi:\s*/i', '', $identifier) ?? $identifier;

        $doi = $this->dataCite->normalizeDoi($identifier);

        if ($doi === null || preg_match('#^10\.\d{4,9}/\S+$#i', $doi) !== 1) {
            return null;
        }

        return mb_strtolower($doi);
    }

    private function resolveFromLiteralUrlCache(string $identifier, string $identifierType): ?string
    {
        if ($identifierType !== 'URL' || $identifier === '') {
            return null;
        }

        $citation = $this->legacyCitationCache->findUrl($identifier);

        return is_string($citation) && trim($citation) !== ''
            ? trim($citation)
            : null;
    }

    private function resolveNormalizedDoi(string $doi, ?float $timeoutSeconds = null): ?string
    {
        $legacyCitation = $this->legacyCitationCache->find($doi);

        if (is_string($legacyCitation) && trim($legacyCitation) !== '') {
            return trim($legacyCitation);
        }

        return $this->resolveFromDoiMetadata($doi, $timeoutSeconds);
    }

    private function resolveFromDoiMetadata(string $doi, ?float $timeoutSeconds = null): ?string
    {
        $metadata = $timeoutSeconds === null
            ? $this->dataCite->getMetadata($doi)
            : $this->dataCite->getMetadata($doi, $timeoutSeconds, false);

        if (! is_array($metadata)) {
            return null;
        }

        $citation = trim($this->dataCite->buildCitationFromMetadata($metadata));

        return $citation !== '' ? $citation : null;
    }
}
