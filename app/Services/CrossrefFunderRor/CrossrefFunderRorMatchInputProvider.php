<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

use App\Models\FundingReference;
use Illuminate\Support\Collection;

/**
 * Reads funding references that are eligible for Crossref Funder ID to ROR normalization.
 */
class CrossrefFunderRorMatchInputProvider
{
    private const string TARGET_TYPE = 'funding_reference';

    public function __construct(
        private readonly CrossrefFunderRorIdentifierNormalizer $normalizer = new CrossrefFunderRorIdentifierNormalizer,
    ) {}

    /**
     * @return Collection<int, CrossrefFunderRorMatchInput>
     */
    public function pendingInputs(): Collection
    {
        $rows = FundingReference::query()
            ->with('funderIdentifierType')
            ->whereNotNull('funder_identifier')
            ->where('funder_identifier', '!=', '')
            ->whereHas('resource')
            ->whereHas('funderIdentifierType', function ($query): void {
                $query->where('name', 'Crossref Funder ID')
                    ->orWhere('slug', 'Crossref Funder ID');
            })
            ->orderBy('id')
            ->get();

        return $rows
            ->map(fn (FundingReference $fundingReference): ?CrossrefFunderRorMatchInput => $this->toInput($fundingReference))
            ->filter()
            ->values();
    }

    private function toInput(FundingReference $fundingReference): ?CrossrefFunderRorMatchInput
    {
        $normalized = $this->normalizer->normalizeCrossrefFunderId(
            $fundingReference->funder_identifier,
            allowBareSuffix: true,
        );

        if ($normalized === null) {
            return null;
        }

        $type = $fundingReference->funderIdentifierType;

        if ($type === null) {
            return null;
        }

        return new CrossrefFunderRorMatchInput(
            resourceId: $fundingReference->resource_id,
            targetType: self::TARGET_TYPE,
            targetId: $fundingReference->id,
            funderName: trim($fundingReference->funder_name),
            funderIdentifier: $normalized['identifier'],
            funderIdentifierType: $type->name,
            schemeUri: $this->normalizer->filledString($fundingReference->scheme_uri),
            normalizedCrossrefFunderId: $normalized['normalized'],
            canonicalCrossrefFunderIdentifier: $normalized['canonical'],
            awardNumber: $this->normalizer->filledString($fundingReference->award_number),
            awardUri: $this->normalizer->filledString($fundingReference->award_uri),
            awardTitle: $this->normalizer->filledString($fundingReference->award_title),
        );
    }
}
