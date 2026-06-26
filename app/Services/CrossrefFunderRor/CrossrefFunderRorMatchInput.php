<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

/**
 * One funding reference that can be checked for Crossref Funder ID to ROR normalization.
 */
final readonly class CrossrefFunderRorMatchInput
{
    public function __construct(
        public int $resourceId,
        public string $targetType,
        public int $targetId,
        public string $funderName,
        public string $funderIdentifier,
        public string $funderIdentifierType,
        public ?string $schemeUri,
        public string $normalizedCrossrefFunderId,
        public string $canonicalCrossrefFunderIdentifier,
        public ?string $awardNumber = null,
        public ?string $awardUri = null,
        public ?string $awardTitle = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentPayload(): array
    {
        return array_filter([
            'funding_reference_id' => $this->targetId,
            'resource_id' => $this->resourceId,
            'funder_name' => $this->funderName,
            'funder_identifier' => $this->funderIdentifier,
            'funder_identifier_type' => $this->funderIdentifierType,
            'scheme_uri' => $this->schemeUri,
            'normalized_crossref_funder_id' => $this->normalizedCrossrefFunderId,
            'canonical_crossref_funder_identifier' => $this->canonicalCrossrefFunderIdentifier,
            'award_number' => $this->awardNumber,
            'award_uri' => $this->awardUri,
            'award_title' => $this->awardTitle,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
