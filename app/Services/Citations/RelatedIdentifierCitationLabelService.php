<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Services\DataCiteApiService;

class RelatedIdentifierCitationLabelService
{
    public function __construct(
        private readonly DataCiteApiService $dataCite,
    ) {}

    public function resolve(string $identifier, string $identifierType): ?string
    {
        $doi = $this->extractResolvableDoi($identifier, $identifierType);

        if ($doi === null) {
            return null;
        }

        $metadata = $this->dataCite->getMetadata($doi);

        if (! is_array($metadata)) {
            return null;
        }

        $citation = trim($this->dataCite->buildCitationFromMetadata($metadata));

        return $citation !== '' ? $citation : null;
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