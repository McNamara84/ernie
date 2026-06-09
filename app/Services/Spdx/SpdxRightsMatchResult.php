<?php

declare(strict_types=1);

namespace App\Services\Spdx;

/**
 * Result of matching one imported rights statement against the SPDX lookup.
 *
 * A result can be:
 * - matched: safe enough to create a reviewer-facing suggestion.
 * - unsupported: clearly custom, commercial, ambiguous, or otherwise non-SPDX.
 * - insufficient: there was not enough source evidence to decide anything.
 */
final readonly class SpdxRightsMatchResult
{
    private function __construct(
        public string $status,
        public ?SpdxLicenseData $license,
        public ?float $score,
        public ?string $matchType,
        public string $reason,
    ) {}

    public static function matched(
        SpdxLicenseData $license,
        float $score,
        string $matchType,
        string $reason,
    ): self {
        return new self('matched', $license, $score, $matchType, $reason);
    }

    public static function unsupported(string $reason): self
    {
        return new self('unsupported', null, null, null, $reason);
    }

    public static function insufficient(string $reason): self
    {
        return new self('insufficient', null, null, null, $reason);
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched' && $this->license instanceof SpdxLicenseData;
    }

    /**
     * Convert a successful match into the metadata stored on AssistantSuggestion.
     *
     * The generic assistant table stores one value and one label at the top
     * level. The rich review context goes into metadata so the UI and acceptance
     * code can still understand why the suggestion exists.
     *
     * @return array<string, mixed>
     */
    public function toSuggestionMetadata(SpdxRightsMatchInput $input): array
    {
        if (! $this->isMatched()) {
            throw new \LogicException('Only matched SPDX rights can produce suggestion metadata.');
        }

        /** @var SpdxLicenseData $license */
        $license = $this->license;

        return [
            'contract_version' => '1.1',
            'action' => 'link_right',
            'current' => $input->currentPayload(),
            'proposed' => [
                'rights' => $license->name,
                'rights_uri' => $license->rightsUri,
                'rights_identifier' => $license->identifier,
                'rights_identifier_scheme' => SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME,
                'scheme_uri' => $license->schemeUri ?? SpdxLicenseLookup::SCHEME_URI,
                'language' => $input->language,
            ],
            'source' => 'spdx',
            'source_url' => SpdxLicenseLookup::licensePageUrl($license->identifier),
            'evidence' => [
                'matched_from' => $this->matchType,
                'reason' => $this->reason,
            ],
        ];
    }
}
