<?php

declare(strict_types=1);

namespace Modules\Assistants\CrossrefFunderRorSuggestion;

use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\CrossrefFunderRor\CrossrefFunderRorAcceptanceService;
use App\Services\CrossrefFunderRor\CrossrefFunderRorDiscoveryService;
use Closure;

final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly CrossrefFunderRorDiscoveryService $discoveryService,
        private readonly CrossrefFunderRorAcceptanceService $acceptanceService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /**
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

    /** @return array{success: bool, message: string} */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return $this->acceptanceService->accept($suggestion);
    }
}
