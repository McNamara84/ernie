<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

/**
 * Provenance for one local vocabulary cache used during subject enrichment.
 */
final readonly class SubjectVocabularySource
{
    public function __construct(
        public string $scheme,
        public string $schemeUri,
        public string $source,
        public string $sourceRegistryUrl,
        public string $localCacheFile,
        public ?string $localCacheUpdatedAt,
        public ?string $version,
        public ?string $generatedBy,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_scheme' => $this->scheme,
            'source_scheme_uri' => $this->schemeUri,
            'source_registry_url' => $this->sourceRegistryUrl,
            'source_file' => $this->localCacheFile,
            'source_generated_by' => $this->generatedBy,
            'source_retrieved_at' => $this->localCacheUpdatedAt,
            'source_version' => $this->version,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function provenance(string $matchingStrategy, ?string $pathNormalizationApplied = null): array
    {
        return array_filter(
            array_merge($this->toArray(), [
                'matching_strategy' => $matchingStrategy,
                'path_normalization_applied' => $pathNormalizationApplied,
            ]),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
