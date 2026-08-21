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
        $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

        if ($doi === null) {
            return null;
        }

        $legacyCitation = $this->legacyCitationCache->find($doi);

        if (is_string($legacyCitation) && trim($legacyCitation) !== '') {
            return trim($legacyCitation);
        }

        return $this->resolveFromDoiMetadata($doi, $timeoutSeconds);
    }

    public function resolveBestEffort(string $identifier, string $identifierType, float $deadline): ?string
    {
        $remainingBudget = $deadline - microtime(true);

        if ($remainingBudget <= 0) {
            return null;
        }

        $timeoutSeconds = min(self::DEFAULT_PER_REQUEST_TIMEOUT_SECONDS, $remainingBudget);

        if ($timeoutSeconds < self::MIN_REQUEST_TIMEOUT_SECONDS) {
            return null;
        }

        return $this->resolve($identifier, $identifierType, $timeoutSeconds);
    }

    /**
     * Resolve a complete import list within one shared best-effort deadline.
     *
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array<int, array<string, mixed>>
     */
    public function resolveBestEffortBatch(array $relatedIdentifiers, float $deadline): array
    {
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

            if ($identifierType !== 'DOI' && $identifierType !== 'URL') {
                continue;
            }

            $doi = $this->normalizeResolvableDoi($identifier, $identifierType);

            if ($doi === null) {
                continue;
            }

            $indexesByDoi[$doi] ??= [];
            $indexesByDoi[$doi][] = $index;
        }

        $legacyHitCount = 0;
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
                $legacyHitCount++;
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
            'legacy_hits' => $legacyHitCount,
            'doi_metadata_hits' => $metadataHitCount,
            'unresolved_count' => count($unresolved),
            'unresolved' => $unresolved,
        ]);

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
