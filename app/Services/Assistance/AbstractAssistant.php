<?php

declare(strict_types=1);

namespace App\Services\Assistance;

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
            fn (Model $model) => $this->transform($model),
        );
    }

    public function acceptSuggestion(int $id): array
    {
        $suggestion = $this->findById($id);

        if ($suggestion === null) {
            return ['success' => false, 'message' => 'Suggestion not found.'];
        }

        return $this->accept($suggestion);
    }

    public function declineSuggestion(int $id, User $user, ?string $reason): void
    {
        $suggestion = $this->findById($id);

        if ($suggestion === null) {
            return;
        }

        $this->decline($suggestion, $user, $reason);
    }
}
