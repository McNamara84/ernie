<?php

declare(strict_types=1);

namespace Modules\Assistants\SubjectMetadataEnrichment;

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\SubjectEnrichment\SubjectEnrichmentAcceptanceService;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use Closure;

final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly SubjectEnrichmentDiscoveryService $discoveryService,
        private readonly SubjectEnrichmentAcceptanceService $acceptanceService,
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
            ): bool => $this->storeOrRefreshSuggestion(
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
     * @param  array<string, mixed>|null  $metadata
     */
    private function storeOrRefreshSuggestion(
        int $resourceId,
        string $targetType,
        int $targetId,
        string $suggestedValue,
        string $suggestedLabel,
        ?float $similarityScore = null,
        ?array $metadata = null,
    ): bool {
        $isDismissed = AssistantDismissed::where('assistant_id', $this->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('dismissed_value', $suggestedValue)
            ->exists();

        if ($isDismissed) {
            return false;
        }

        $suggestion = AssistantSuggestion::where('assistant_id', $this->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('suggested_value', $suggestedValue)
            ->first();

        $attributes = [
            'resource_id' => $resourceId,
            'suggested_label' => $suggestedLabel,
            'similarity_score' => $similarityScore,
            'metadata' => $metadata,
        ];

        if (! $suggestion instanceof AssistantSuggestion) {
            AssistantSuggestion::create(array_merge([
                'assistant_id' => $this->getId(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'suggested_value' => $suggestedValue,
                'discovered_at' => now(),
            ], $attributes));

            return true;
        }

        $suggestion->forceFill($attributes);
        if (! $suggestion->isDirty()) {
            return false;
        }

        $suggestion->discovered_at = now();
        $suggestion->save();

        return true;
    }

    /** @return array{success: bool, message: string} */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return $this->acceptanceService->accept($suggestion);
    }
}
