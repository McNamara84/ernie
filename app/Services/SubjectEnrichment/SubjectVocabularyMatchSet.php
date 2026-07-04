<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

/**
 * Candidate concepts returned by one lookup strategy.
 */
final readonly class SubjectVocabularyMatchSet
{
    /**
     * @param  list<SubjectVocabularyConcept>  $candidates
     */
    public function __construct(
        public array $candidates,
        public ?string $pathNormalizationApplied = null,
    ) {}

    public static function empty(?string $pathNormalizationApplied = null): self
    {
        return new self([], $pathNormalizationApplied);
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function isUnique(): bool
    {
        return count($this->candidates) === 1;
    }

    public function sole(): ?SubjectVocabularyConcept
    {
        return $this->isUnique() ? $this->candidates[0] : null;
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    /**
     * @return list<string>
     */
    public function candidateIds(): array
    {
        return array_map(
            static fn (SubjectVocabularyConcept $concept): string => $concept->id,
            $this->candidates,
        );
    }
}
