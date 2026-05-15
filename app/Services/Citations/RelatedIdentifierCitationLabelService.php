<?php

declare(strict_types=1);

namespace App\Services\Citations;

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