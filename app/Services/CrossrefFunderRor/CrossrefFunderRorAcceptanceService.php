<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

use App\Models\AssistantSuggestion;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Applies accepted Crossref Funder ID to ROR suggestions.
 */
final readonly class CrossrefFunderRorAcceptanceService
{
    public function __construct(
        private DataCiteSyncService $dataCiteSyncService,
        private CrossrefFunderRorIdentifierNormalizer $normalizer = new CrossrefFunderRorIdentifierNormalizer,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function accept(AssistantSuggestion $suggestion): array
    {
        $validation = $this->validatedPayload($suggestion);

        if ($validation['success'] === false) {
            return $validation;
        }

        /** @var array{ror_id: string, normalized_crossref_funder_id: string} $payload */
        $payload = $validation['payload'];

        $result = DB::transaction(function () use ($suggestion, $payload): array {
            $fundingReference = FundingReference::query()
                ->with('resource')
                ->whereKey($suggestion->target_id)
                ->where('resource_id', $suggestion->resource_id)
                ->lockForUpdate()
                ->first();

            if (! $fundingReference instanceof FundingReference) {
                return [
                    'success' => false,
                    'message' => 'The funding reference for this Crossref-to-ROR suggestion no longer exists.',
                ];
            }

            if (! $this->isCrossrefFunderType($fundingReference)) {
                return [
                    'success' => false,
                    'message' => 'This funding reference is no longer typed as a Crossref Funder ID.',
                ];
            }

            $current = $this->normalizer->normalizeCrossrefFunderId(
                $fundingReference->funder_identifier,
                allowBareSuffix: true,
            );

            if ($current === null || $current['normalized'] !== $payload['normalized_crossref_funder_id']) {
                return [
                    'success' => false,
                    'message' => 'This funding reference changed since the suggestion was created. Please refresh the assistant list.',
                ];
            }

            $rorType = $this->rorFunderIdentifierType();

            if (! $rorType instanceof FunderIdentifierType) {
                return [
                    'success' => false,
                    'message' => 'The local ROR funder identifier type is missing.',
                ];
            }

            $fundingReference->update([
                'funder_identifier' => $payload['ror_id'],
                'funder_identifier_type_id' => $rorType->id,
                'scheme_uri' => CrossrefFunderRorIdentifierNormalizer::ROR_SCHEME_URI,
            ]);

            AssistantSuggestion::query()
                ->where('assistant_id', CrossrefFunderRorDiscoveryService::ASSISTANT_ID)
                ->where('target_type', CrossrefFunderRorDiscoveryService::TARGET_TYPE)
                ->where('target_id', $fundingReference->id)
                ->where('id', '!=', $suggestion->id)
                ->delete();

            return [
                'success' => true,
                'message' => 'Funding reference identifier normalized to ROR.',
                'funding_reference' => $fundingReference->fresh('resource'),
            ];
        });

        if ($result['success'] !== true || ! ($result['funding_reference'] ?? null) instanceof FundingReference) {
            return [
                'success' => (bool) $result['success'],
                'message' => (string) $result['message'],
            ];
        }

        $fundingReference = $result['funding_reference'];
        $syncResult = $this->dataCiteSyncService->syncIfRegistered($fundingReference->resource);

        if ($syncResult->hasFailed()) {
            return [
                'success' => true,
                'message' => 'Funding reference identifier normalized to ROR. DataCite sync was attempted but did not complete: '.$syncResult->errorMessage,
            ];
        }

        return [
            'success' => true,
            'message' => 'Funding reference identifier normalized to ROR.',
        ];
    }

    /**
     * @return array{success: false, message: string}|array{success: true, payload: array{ror_id: string, normalized_crossref_funder_id: string}}
     */
    private function validatedPayload(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type !== CrossrefFunderRorDiscoveryService::TARGET_TYPE) {
            return [
                'success' => false,
                'message' => 'This Crossref-to-ROR suggestion targets an unsupported entity type.',
            ];
        }

        $metadata = $suggestion->metadata ?? [];
        $current = is_array($metadata['current'] ?? null) ? $metadata['current'] : [];
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];

        $rorId = $this->normalizer->canonicalRorIdentifier($proposed['funder_identifier'] ?? null);
        $normalizedCrossrefFunderId = $this->normalizer->filledString($current['normalized_crossref_funder_id'] ?? null);
        $proposedType = $this->normalizer->filledString($proposed['funder_identifier_type'] ?? null);
        $schemeUri = $this->normalizer->filledString($proposed['scheme_uri'] ?? null);

        if ($rorId === null || $normalizedCrossrefFunderId === null) {
            return [
                'success' => false,
                'message' => 'This suggestion does not contain complete Crossref-to-ROR metadata.',
            ];
        }

        if ($rorId !== $suggestion->suggested_value) {
            return [
                'success' => false,
                'message' => 'The suggestion value and proposed ROR identifier do not match.',
            ];
        }

        if ($proposedType !== 'ROR' || $schemeUri !== CrossrefFunderRorIdentifierNormalizer::ROR_SCHEME_URI) {
            return [
                'success' => false,
                'message' => 'Only ROR funder identifier suggestions can be accepted by this assistant.',
            ];
        }

        return [
            'success' => true,
            'payload' => [
                'ror_id' => $rorId,
                'normalized_crossref_funder_id' => $normalizedCrossrefFunderId,
            ],
        ];
    }

    private function isCrossrefFunderType(FundingReference $fundingReference): bool
    {
        $type = $fundingReference->funderIdentifierType;

        return $type instanceof FunderIdentifierType
            && ($type->name === 'Crossref Funder ID' || $type->slug === 'Crossref Funder ID');
    }

    private function rorFunderIdentifierType(): ?FunderIdentifierType
    {
        return FunderIdentifierType::query()
            ->where('name', 'ROR')
            ->orWhere('slug', 'ROR')
            ->first();
    }
}
