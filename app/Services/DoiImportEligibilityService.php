<?php

declare(strict_types=1);

namespace App\Services;

class DoiImportEligibilityService
{
    public function __construct(
        private readonly LegacyResourceLookupService $legacyResourceLookupService,
        private readonly DoiSuggestionService $doiSuggestionService,
    ) {}

    /**
     * @throws \Throwable when the SUMARIOPMD fallback lookup is unavailable
     */
    public function canImport(string $doi): bool
    {
        $normalisedDoi = $this->normaliseDoi($doi);

        if ($this->hasConfiguredDataCitePrefix($normalisedDoi)) {
            return true;
        }

        return $this->legacyResourceLookupService->existsByDoi($normalisedDoi);
    }

    public function hasConfiguredDataCitePrefix(string $doi): bool
    {
        $prefix = $this->extractPrefix($this->normaliseDoi($doi));

        if ($prefix === null) {
            return false;
        }

        return in_array($prefix, $this->configuredProductionPrefixes(), true);
    }

    /**
     * @return list<string>
     */
    public function configuredProductionPrefixes(): array
    {
        $prefixes = config('datacite.production.prefixes', []);

        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $prefix): string => strtolower(trim((string) $prefix)),
                $prefixes,
            ),
            static fn (string $prefix): bool => $prefix !== '',
        )));
    }

    private function normaliseDoi(string $doi): string
    {
        $normalised = $this->doiSuggestionService->normalizeDoi($doi);

        return $normalised !== '' ? $normalised : strtolower(trim($doi));
    }

    private function extractPrefix(string $doi): ?string
    {
        $slashPosition = strpos($doi, '/');

        if ($slashPosition === false || $slashPosition === 0) {
            return null;
        }

        return strtolower(substr($doi, 0, $slashPosition));
    }
}
