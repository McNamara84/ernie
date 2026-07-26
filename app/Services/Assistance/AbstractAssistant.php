<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Enums\CacheKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base class for existing assistant modules that use their own database tables.
 *
 * Existing assistants (ORCID, ROR, Relations) extend this class and delegate
 * to their proven discovery services, jobs, and models. This class acts as
 * an adapter between the modular AssistantContract interface and the legacy code.
 *
 * For NEW student-created assistants, use GenericTableAssistant instead.
 */
abstract class AbstractAssistant implements AssistantContract
{
    protected AssistantManifest $manifest;

    public function __construct()
    {
        $this->manifest = AssistantManifest::fromFile($this->getManifestPath());
    }

    /**
     * Return the absolute path to this module's manifest.json file.
     *
     * Typically: __DIR__ . '/manifest.json'
     */
    abstract protected function getManifestPath(): string;

    /**
     * Query the module's own suggestion table and return a paginator of raw models.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    abstract protected function query(int $perPage): LengthAwarePaginator;

    /**
     * Transform a single suggestion model into an array for the frontend.
     *
     * @return array<string, mixed>
     */
    abstract protected function transform(Model $suggestion): array;

    /**
     * Find a suggestion by its primary key.
     */
    abstract protected function findById(int $id): ?Model;

    /**
     * Apply the accepted suggestion to the actual entity.
     *
     * @return array<string, mixed> Result data (success status, message, etc.)
     */
    abstract protected function accept(Model $suggestion): array;

    /**
     * Record a declined suggestion so it won't be suggested again.
     */
    abstract protected function decline(Model $suggestion, User $user, ?string $reason): void;

    // ── AssistantContract implementation ─────────────────────────────

    public function getId(): string
    {
        return $this->manifest->id;
    }

    public function getName(): string
    {
        return $this->manifest->name;
    }

    public function getManifest(): AssistantManifest
    {
        return $this->manifest;
    }

    public function getJobStatusCacheKey(string $jobId): string
    {
        return "{$this->manifest->cacheKeyPrefix}:{$jobId}";
    }

    public function getLockKey(): string
    {
        return $this->manifest->lockKey;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function loadSuggestions(int $perPage): LengthAwarePaginator
    {
        return $this->query($perPage)->through(
            fn (Model $model) => $this->present($model),
        );
    }

    /**
     * Add assistant identity and action capabilities to a transformed item.
     *
     * @return array<string, mixed>
     */
    protected function present(Model $suggestion): array
    {
        return $this->presentTransformed($suggestion, $this->transform($suggestion));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function presentTransformed(Model $suggestion, array $item): array
    {
        $metadata = $this->reviewMetadata($suggestion, $item);

        $item['assistant_id'] = $this->getId();
        $item['review'] = [
            'assistant_id' => $this->getId(),
            'assistant_name' => $this->getName(),
            'route_prefix' => $this->getManifest()->routePrefix,
            'can_accept' => $metadata['can_accept'],
            'can_decline' => $metadata['can_decline'],
            'exclusive_target_key' => $metadata['exclusive_target_key'],
            'label' => $metadata['label'],
        ];

        return $item;
    }

    /**
     * Describe how a suggestion participates in the shared review workflow.
     *
     * @param  array<string, mixed>  $item
     * @return array{can_accept: bool, can_decline: bool, exclusive_target_key: string|null, label: string}
     */
    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        return [
            'can_accept' => true,
            'can_decline' => true,
            'exclusive_target_key' => null,
            'label' => $this->reviewLabel($item, (int) $suggestion->getKey()),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function reviewLabel(array $item, int $id): string
    {
        foreach (['suggested_label', 'suggested_value', 'identifier', 'suggested_orcid', 'suggested_name'] as $key) {
            $value = $item[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Suggestion #'.$id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSuggestionForReview(int $id): ?array
    {
        $suggestion = $this->findById($id);

        return $suggestion === null ? null : $this->present($suggestion);
    }

    public function acceptSuggestion(int $id): array
    {
        $suggestion = $this->findById($id);

        if ($suggestion === null) {
            return ['success' => false, 'message' => 'Suggestion not found.'];
        }

        $result = $this->accept($suggestion);

        $this->forgetTotalPendingCount();

        return $result;
    }

    public function declineSuggestion(int $id, User $user, ?string $reason): array
    {
        $suggestion = $this->findById($id);

        if ($suggestion === null) {
            return ['success' => false, 'message' => 'Suggestion not found.'];
        }

        $this->decline($suggestion, $user, $reason);

        $this->forgetTotalPendingCount();

        return ['success' => true, 'message' => 'Suggestion declined.'];
    }

    /**
     * Invalidate the cached total pending count so the sidebar badge updates.
     */
    private function forgetTotalPendingCount(): void
    {
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();
    }
}
