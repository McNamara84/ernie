<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

/**
 * Result of trying to enrich one subject row.
 */
final readonly class SubjectEnrichmentMatchResult
{
    /**
     * @param  list<string>  $matchedFields
     * @param  list<string>  $warnings
     * @param  array<string, string>  $warningMessages
     * @param  list<string>  $suppressionReasons
     * @param  list<string>  $candidateIds
     */
    private function __construct(
        public string $status,
        public ?SubjectVocabularyConcept $concept,
        public ?string $matchingStrategy,
        public array $matchedFields,
        public array $warnings,
        public array $warningMessages,
        public array $suppressionReasons,
        public int $candidateCount,
        public array $candidateIds,
        public ?string $pathNormalizationApplied,
    ) {}

    /**
     * @param  list<string>  $matchedFields
     * @param  list<string>  $warnings
     * @param  array<string, string>  $warningMessages
     */
    public static function matched(
        SubjectVocabularyConcept $concept,
        string $matchingStrategy,
        array $matchedFields,
        array $warnings = [],
        array $warningMessages = [],
        ?string $pathNormalizationApplied = null,
    ): self {
        return new self(
            status: 'matched',
            concept: $concept,
            matchingStrategy: $matchingStrategy,
            matchedFields: $matchedFields,
            warnings: array_values(array_unique($warnings)),
            warningMessages: $warningMessages,
            suppressionReasons: [],
            candidateCount: 1,
            candidateIds: [$concept->id],
            pathNormalizationApplied: $pathNormalizationApplied,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $candidateIds
     */
    public static function suppressed(
        array $reasons,
        int $candidateCount = 0,
        array $candidateIds = [],
        ?string $pathNormalizationApplied = null,
    ): self {
        return new self(
            status: 'suppressed',
            concept: null,
            matchingStrategy: null,
            matchedFields: [],
            warnings: [],
            warningMessages: [],
            suppressionReasons: array_values(array_unique($reasons)),
            candidateCount: $candidateCount,
            candidateIds: array_values(array_unique($candidateIds)),
            pathNormalizationApplied: $pathNormalizationApplied,
        );
    }
}
