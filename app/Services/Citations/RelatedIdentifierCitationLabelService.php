<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Exceptions\IncompleteCitationLabelResolutionException;
use App\Services\DataCiteApiService;

class RelatedIdentifierCitationLabelService
{
    public const DEFAULT_AGGREGATE_TIMEOUT_SECONDS = 2.0;

    public const DEFAULT_PER_REQUEST_TIMEOUT_SECONDS = 0.75;

    private const MIN_REQUEST_TIMEOUT_SECONDS = 0.1;

    public function __construct(
        private readonly DataCiteApiService $dataCite,
    ) {}

    public function resolve(string $identifier, string $identifierType, ?float $timeoutSeconds = null): ?string
    {
        $doi = $this->extractResolvableDoi($identifier, $identifierType);

        if ($doi === null) {
            return null;
        }

        $metadata = $timeoutSeconds === null
            ? $this->dataCite->getMetadata($doi)
            : $this->dataCite->getMetadata($doi, $timeoutSeconds, false);

        if (! is_array($metadata)) {
            return null;
        }

        $citation = trim($this->dataCite->buildCitationFromMetadata($metadata));

        return $citation !== '' ? $citation : null;
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
     * Resolve every missing citation label required by a single-resource import.
     *
     * Existing labels are preserved. DOI-like URL identifiers participate in
     * strict resolution, while unrelated URL and non-DOI identifier types stay
     * optional because the current citation provider cannot resolve them.
     *
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array<int, array<string, mixed>>
     *
     * @throws IncompleteCitationLabelResolutionException
     */
    public function resolveRequired(array $relatedIdentifiers): array
    {
        /** @var array<string, list<int>> $indexesByDoi */
        $indexesByDoi = [];
        /** @var array<string, string> $failures */
        $failures = [];

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

                continue;
            }

            if ($identifierType !== 'DOI' && $identifierType !== 'URL') {
                continue;
            }

            $doi = $this->extractResolvableDoi($identifier, $identifierType);

            if ($doi === null) {
                if ($identifierType === 'DOI') {
                    $position = $index + 1;
                    $failureKey = $identifier !== '' ? $identifier : "[empty DOI at position {$position}]";
                    $failures[$failureKey] = 'The identifier is not a valid resolvable DOI.';
                }

                continue;
            }

            $indexesByDoi[$doi] ??= [];
            $indexesByDoi[$doi][] = $index;
        }

        if ($indexesByDoi !== []) {
            $outcomes = $this->dataCite->getMetadataBatch(array_keys($indexesByDoi));

            foreach ($indexesByDoi as $doi => $indexes) {
                $outcome = $outcomes[$doi] ?? null;

                if (! is_array($outcome)) {
                    $failures[$doi] = 'The DOI metadata service returned no result.';

                    continue;
                }

                if ($outcome['status'] !== 'resolved') {
                    $failures[$doi] = $outcome['reason'];

                    continue;
                }

                $citation = trim($this->dataCite->buildCitationFromMetadata($outcome['metadata']));

                if ($citation === '') {
                    $failures[$doi] = 'The DOI metadata did not produce a citation label.';

                    continue;
                }

                foreach ($indexes as $index) {
                    $relatedIdentifiers[$index]['citationLabel'] = $citation;
                }
            }
        }

        if ($failures !== []) {
            throw new IncompleteCitationLabelResolutionException($failures);
        }

        return $relatedIdentifiers;
    }

    private function extractResolvableDoi(string $identifier, string $identifierType): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $normalizedType = trim($identifierType);
        if ($normalizedType !== 'DOI' && $normalizedType !== 'URL') {
            return null;
        }

        $doi = $this->dataCite->normalizeDoi($identifier);

        if ($doi === null || ! preg_match('#^10\.\d{4,9}/.+$#', $doi)) {
            return null;
        }

        return $doi;
    }
}
