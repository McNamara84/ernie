<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Jobs\DiscoverAssistantSuggestionsJob;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base class for NEW student-created assistant modules that use the generic tables.
 *
 * Students extend this class and only need to implement two methods:
 * - discover(): Query an external API and store suggestions via storeSuggestion()
 * - applyAccepted(): Update the actual entity when a curator accepts a suggestion
 *
 * Everything else (storage, pagination, decline logic, job dispatch) is automatic.
 *
 * Example:
 *   class SpdxLicenseAssistant extends GenericTableAssistant
 *   {
 *       protected function getManifestPath(): string { return __DIR__ . '/manifest.json'; }
 *       protected function discover(Closure $onProgress): int { ... }
 *       protected function applyAccepted(AssistantSuggestion $suggestion): array { ... }
 *   }
 */
abstract class GenericTableAssistant extends AbstractAssistant
{
    /**
     * Query an external API and store new suggestions.
     *
     * Use $this->storeSuggestion(...) to store each discovered suggestion.
     * Call $onProgress('message') to report progress to the job queue.
     *
     * @param  Closure(string): void  $onProgress  Call this to report progress messages
     * @return int  Number of new suggestions stored
     */
    abstract protected function discover(Closure $onProgress): int;

    /**
     * Apply an accepted suggestion to the actual database entity.
     *
     * Called when a curator clicks "Accept". Should update the target entity
     * (e.g. set a license identifier, add a related identifier, etc.)
     *
     * @return array{success: bool, message: string}
     */
    abstract protected function applyAccepted(AssistantSuggestion $suggestion): array;

    // ── GenericTableAssistant provides all storage logic ─────────────

    #[\Override]
    protected function query(int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Model> */
        return AssistantSuggestion::where('assistant_id', $this->getId())
            ->with('resource')
            ->orderByDesc('discovered_at')
            ->paginate($perPage, ['*'], $this->getId() . '_page');
    }

    #[\Override]
    protected function transform(Model $suggestion): array
    {
        /** @var AssistantSuggestion $suggestion */
        return [
            'id' => $suggestion->id,
            'assistant_id' => $suggestion->assistant_id,
            'resource_id' => $suggestion->resource_id,
            'resource_doi' => $suggestion->resource->doi ?? '',
            'resource_title' => $suggestion->resource->mainTitle ?? 'Untitled',
            'target_type' => $suggestion->target_type,
            'target_id' => $suggestion->target_id,
            'suggested_value' => $suggestion->suggested_value,
            'suggested_label' => $suggestion->suggested_label,
            'similarity_score' => $suggestion->similarity_score,
            'metadata' => $suggestion->metadata,
            'discovered_at' => $suggestion->discovered_at->toIso8601String(),
        ];
    }

    #[\Override]
    protected function findById(int $id): ?Model
    {
        return AssistantSuggestion::where('assistant_id', $this->getId())
            ->where('id', $id)
            ->first();
    }

    #[\Override]
    public function countPending(): int
    {
        return AssistantSuggestion::where('assistant_id', $this->getId())->count();
    }

    #[\Override]
    protected function accept(Model $suggestion): array
    {
        /** @var AssistantSuggestion $suggestion */
        $result = $this->applyAccepted($suggestion);

        if ($result['success']) {
            $suggestion->delete();
        }

        return $result;
    }

    #[\Override]
    protected function decline(Model $suggestion, User $user, ?string $reason): void
    {
        /** @var AssistantSuggestion $suggestion */
        AssistantDismissed::create([
            'assistant_id' => $this->getId(),
            'target_type' => $suggestion->target_type,
            'target_id' => $suggestion->target_id,
            'dismissed_value' => $suggestion->suggested_value,
            'dismissed_by' => $user->id,
            'reason' => $reason,
        ]);

        $suggestion->delete();
    }

    #[\Override]
    public function dispatchDiscovery(string $jobId, string $lockOwner): void
    {
        DiscoverAssistantSuggestionsJob::dispatch($this->getId(), $jobId, $lockOwner);
    }

    /**
     * Run the discovery process. Called by the generic job.
     *
     * @param  Closure(string): void  $onProgress
     * @return int  Number of new suggestions found
     */
    public function runDiscovery(Closure $onProgress): int
    {
        return $this->discover($onProgress);
    }

    /**
     * Store a suggestion in the generic assistant_suggestions table.
     *
     * Automatically skips duplicates (same assistant + target + value).
     * Also skips suggestions that have been previously dismissed.
     *
     * @param  int|null  $resourceId  FK to resources table (nullable)
     * @param  string  $targetType  Entity type being enriched (e.g. "right", "person")
     * @param  int  $targetId  Primary key of the entity being enriched
     * @param  string  $suggestedValue  The suggested value (e.g. SPDX identifier, ORCID)
     * @param  string  $suggestedLabel  Human-readable label for the suggestion
     * @param  float|null  $similarityScore  Match confidence (0.0 to 1.0), or null
     * @param  array<string, mixed>|null  $metadata  Extra assistant-specific data
     * @return bool  True if stored, false if skipped (duplicate or dismissed)
     */
    protected function storeSuggestion(
        ?int $resourceId,
        string $targetType,
        int $targetId,
        string $suggestedValue,
        string $suggestedLabel,
        ?float $similarityScore = null,
        ?array $metadata = null,
    ): bool {
        // Skip if already dismissed
        $isDismissed = AssistantDismissed::where('assistant_id', $this->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('dismissed_value', $suggestedValue)
            ->exists();

        if ($isDismissed) {
            return false;
        }

        // Skip if already exists (same assistant + target + value)
        $wasRecentlyCreated = AssistantSuggestion::firstOrCreate(
            [
                'assistant_id' => $this->getId(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'suggested_value' => $suggestedValue,
            ],
            [
                'resource_id' => $resourceId,
                'suggested_label' => $suggestedLabel,
                'similarity_score' => $similarityScore,
                'metadata' => $metadata,
                'discovered_at' => now(),
            ],
        )->wasRecentlyCreated;

        return $wasRecentlyCreated;
    }
}
