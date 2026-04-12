<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Contract that every assistant module must implement.
 *
 * An assistant is a metadata enrichment module that discovers suggestions
 * from external APIs and presents them to curators for review.
 *
 * Existing assistants (ORCID, ROR, Relations) extend AbstractAssistant
 * and use their own database tables.
 *
 * New student-created assistants extend GenericTableAssistant and use
 * the shared assistant_suggestions / assistant_dismissed tables.
 */
interface AssistantContract
{
    /**
     * Get the unique identifier for this assistant (kebab-case).
     *
     * Must match the folder name in modules/assistants/.
     * Example: "orcid-suggestion", "spdx-license"
     */
    public function getId(): string;

    /**
     * Get the human-readable display name.
     *
     * Example: "ORCID Suggestions"
     */
    public function getName(): string;

    /**
     * Get the parsed manifest configuration.
     */
    public function getManifest(): AssistantManifest;

    /**
     * Load paginated suggestions for display on the assistance page.
     *
     * Each item in the paginator should be an array (already transformed).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function loadSuggestions(int $perPage): LengthAwarePaginator;

    /**
     * Count the number of pending suggestions.
     */
    public function countPending(): int;

    /**
     * Dispatch the discovery job for this assistant.
     *
     * @param  string  $jobId  UUID for tracking progress
     * @param  string  $lockOwner  Cache lock owner token
     */
    public function dispatchDiscovery(string $jobId, string $lockOwner): void;

    /**
     * Get the cache key used for tracking job progress.
     */
    public function getJobStatusCacheKey(string $jobId): string;

    /**
     * Get the cache lock key that prevents concurrent discovery runs.
     */
    public function getLockKey(): string;

    /**
     * Accept a suggestion by its ID and apply the enrichment.
     *
     * @return array<string, mixed> Result data (e.g. success status, message)
     */
    public function acceptSuggestion(int $id): array;

    /**
     * Decline a suggestion by its ID, recording who declined and why.
     */
    public function declineSuggestion(int $id, User $user, ?string $reason): void;
}
