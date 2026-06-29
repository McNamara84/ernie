<?php

declare(strict_types=1);

namespace Modules\Assistants\DateTypeSuggestion;

use App\Jobs\DiscoverAssistantSuggestionsJob;
use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\DateType\DateTypeSuggestionAcceptanceService;
use App\Services\DateType\DateTypeSuggestionDiscoveryService;
use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\DateType\DateTypeAcceptanceService;
use App\Services\DateType\DateTypeDiscoveryService;
use Closure;

final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly DateTypeSuggestionDiscoveryService $discoveryService,
        private readonly DateTypeSuggestionAcceptanceService $acceptanceService,
        private readonly DateTypeDiscoveryService $discoveryService,
        private readonly DateTypeAcceptanceService $acceptanceService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /**
     * Discover DOI resources where Collected date count matches geolocation count.
     * Discover dateType suggestions for resources.
     *
     * @param  Closure(string): void  $onProgress
     */
    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        return $this->discoveryService->discover(
            assistantId: $this->getId(),
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

    #[\Override]
    public function dispatchDiscovery(string $jobId, string $lockOwner): void
    {
        DiscoverAssistantSuggestionsJob::dispatchSync($this->getId(), $jobId, $lockOwner, $this->getLockKey());
    }

    /** @return array{success: bool, message: string} */
    /**
     * Apply the suggestion when a curator clicks "Accept".
     *
     * @return array{success: bool, message: string}
     */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return $this->acceptanceService->accept($suggestion);
    }
}
