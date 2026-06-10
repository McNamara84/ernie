<?php

declare(strict_types=1);

namespace Modules\Assistants\SpdxLicenseSuggestion;

use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\Spdx\SpdxRightsDiscoveryService;
use Closure;

/**
 * Assistant module that proposes SPDX links for imported rights statements.
 *
 * The module intentionally extends GenericTableAssistant. That base class
 * handles storage, duplicate checks, pagination, decline tracking, and job
 * dispatch. Students can therefore focus on the domain-specific discovery
 * logic in SpdxRightsDiscoveryService.
 */
final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly SpdxRightsDiscoveryService $discoveryService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /**
     * Run SPDX discovery and store suggestions in the generic assistant table.
     *
     * The closure passed into the discovery service is a small adapter around
     * GenericTableAssistant::storeSuggestion(). This keeps the reusable service
     * independent from the assistant UI/storage base class.
     *
     * @param  Closure(string): void  $onProgress
     */
    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        return $this->discoveryService->discover(
            storeSuggestion: fn (
                int $resourceId,
                string $targetType,
                int $targetId,
                string $suggestedValue,
                string $suggestedLabel,
                ?float $similarityScore,
                ?array $metadata,
            ): bool => $this->storeSuggestion(
                resourceId: $resourceId,
                targetType: $targetType,
                targetId: $targetId,
                suggestedValue: $suggestedValue,
                suggestedLabel: $suggestedLabel,
                similarityScore: $similarityScore,
                metadata: $metadata,
            ),
            onProgress: $onProgress,
        );
    }

    /**
     * Issue 820 stops at suggestion generation.
     *
     * Accepting a suggestion means updating the targeted `resource_rights` row
     * and is intentionally left for the follow-up issue. Returning success=false
     * makes that boundary explicit and prevents accidental data writes.
     *
     * @return array{success: bool, message: string}
     */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return [
            'success' => false,
            'message' => 'Accepting SPDX rights suggestions is not supported yet. Please decline this suggestion to dismiss it for now.',
        ];
    }
}
