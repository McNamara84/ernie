<?php

declare(strict_types=1);

namespace Modules\Assistants\LanguageSuggestion;

use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\Language\LanguageSuggestionAcceptanceService;
use App\Services\Language\LanguageSuggestionDiscoveryService;
use Closure;

final class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly LanguageSuggestionDiscoveryService $discoveryService,
        private readonly LanguageSuggestionAcceptanceService $acceptanceService,
    ) {
        parent::__construct();
    }

    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    /**
     * @param  Closure(string): void  $onProgress
     */
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
     * @return array{success: bool, message: string}
     */
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return $this->acceptanceService->accept($suggestion);
    }
}
