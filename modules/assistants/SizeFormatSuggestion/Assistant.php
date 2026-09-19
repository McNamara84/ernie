<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
use App\Contracts\ReportsDiscoveryDetails;
use App\Services\SizeFormat\SizeFormatSuggestionAcceptanceService;
use App\Services\SizeFormat\SizeFormatSuggestionDiscoveryService;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class Assistant extends GenericTableAssistant implements ReportsDiscoveryDetails
{
    public function __construct(
        private readonly SizeFormatSuggestionDiscoveryService $discoveryService,
        private readonly SizeFormatSuggestionAcceptanceService $acceptanceService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /**
     * Discover size and format suggestions for resources.
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

    /** @return array<string, int|string|bool|null> */
    public function discoveryDetails(): array
    {
        return $this->discoveryService->lastReport();
    }

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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    protected function acceptWithInput(Model $suggestion, array $input): array
    {
        if (! $suggestion instanceof AssistantSuggestion) {
            return ['success' => false, 'message' => 'Suggestion not found.'];
        }

        $result = $this->acceptanceService->accept(
            $suggestion,
            $input,
            ! (($input['defer_datacite_sync'] ?? false) === true),
        );

        if (($result['success'] ?? false) === true) {
            $suggestion->delete();
        }

        return $result;
    }
}
