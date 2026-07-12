<?php

declare(strict_types=1);

namespace Modules\Assistants\DescriptionSegmentation;

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\DescriptionSegmentation\DescriptionSegmentationAcceptanceService;
use App\Services\DescriptionSegmentation\DescriptionSegmentationDiscoveryService;
use Closure;

final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly DescriptionSegmentationDiscoveryService $discoveryService,
        private readonly DescriptionSegmentationAcceptanceService $acceptanceService,
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
        $isDismissed = AssistantDismissed::query()
            ->where('assistant_id', $this->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('dismissed_value', $suggestedValue)
            ->exists();

        if ($isDismissed) {
            return false;
        }

        $attributes = [
            'resource_id' => $resourceId,
            'suggested_value' => $suggestedValue,
            'suggested_label' => $suggestedLabel,
            'similarity_score' => $similarityScore,
            'metadata' => $metadata,
        ];

        $suggestion = AssistantSuggestion::query()
            ->where('assistant_id', $this->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if (! $suggestion instanceof AssistantSuggestion) {
            AssistantSuggestion::create([
                'assistant_id' => $this->getId(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                ...$attributes,
                'discovered_at' => now(),
            ]);

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
