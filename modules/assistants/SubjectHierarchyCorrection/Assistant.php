<?php

declare(strict_types=1);

namespace Modules\Assistants\SubjectHierarchyCorrection;

use App\Contracts\ReportsDiscoveryDetails;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\SubjectHierarchy\SubjectHierarchyAcceptanceService;
use App\Services\SubjectHierarchy\SubjectHierarchyDiscoveryService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Assistant extends GenericTableAssistant implements ReportsDiscoveryDetails
{
    public function __construct(private readonly SubjectHierarchyDiscoveryService $discovery, private readonly SubjectHierarchyAcceptanceService $acceptance)
    {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /** @param Closure(string): void $onProgress */
    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        return $this->discovery->discover(function (int $resourceId, string $value, array $metadata): bool {
            return $this->storeSuggestion(
                resourceId: $resourceId,
                targetType: 'subject_hierarchy',
                targetId: $resourceId,
                suggestedValue: $value,
                suggestedLabel: 'Review narrower terms for "'.$metadata['broader_label'].'"',
                metadata: $metadata,
            );
        }, $onProgress);
    }

    /** @return array<string, int|string|bool|null> */
    public function discoveryDetails(): array
    {
        return $this->discovery->report();
    }

    /** @return array{success: bool, message: string} */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return ['success' => false, 'message' => 'Choose narrower terms before accepting.'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[\Override]
    protected function acceptWithInput(Model $suggestion, array $input): array
    {
        if (! $suggestion instanceof AssistantSuggestion) {
            return ['success' => false, 'message' => 'Suggestion not found.'];
        }

        return $this->acceptance->accept($suggestion, $input);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function declineSuggestion(int $id, User $user, ?string $reason): array
    {
        if ($reason === null || trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Explain why the current broader term should be retained (up to 255 characters).']);
        }

        return DB::transaction(function () use ($id, $user, $reason): array {
            $suggestion = AssistantSuggestion::where('assistant_id', $this->getId())->find($id);
            if ($suggestion === null) {
                return ['success' => false, 'message' => 'Suggestion not found.'];
            }
            Resource::whereKey($suggestion->resource_id)->lockForUpdate()->first();
            AssistantSuggestion::whereKey($id)->lockForUpdate()->first();

            return parent::declineSuggestion($id, $user, trim($reason));
        });
    }

    /** @param array<string, mixed> $item
     * @return array{can_accept: bool, can_decline: bool, exclusive_target_key: string|null, label: string}
     */
    #[\Override]
    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        $metadata = $item['metadata'] ?? [];

        return [
            'can_accept' => ! is_array($metadata) || ($metadata['suggestion_kind'] ?? null) !== 'hint',
            'can_decline' => true,
            'exclusive_target_key' => $this->getId().':'.$suggestion->getAttribute('resource_id').':'.(is_array($metadata) ? ($metadata['scheme'] ?? '') : ''),
            'label' => (string) ($item['suggested_label'] ?? 'Review subject hierarchy'),
        ];
    }
}
