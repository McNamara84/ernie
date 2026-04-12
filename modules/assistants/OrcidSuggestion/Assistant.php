<?php

declare(strict_types=1);

namespace Modules\Assistants\OrcidSuggestion;

use App\Jobs\DiscoverOrcidsJob;
use App\Models\SuggestedOrcid;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\OrcidDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Assistant module for discovering ORCID identifiers via the ORCID Public API.
 *
 * Wraps the existing OrcidDiscoveryService and DiscoverOrcidsJob.
 * Uses the existing suggested_orcids / dismissed_orcids tables.
 *
 * Overrides loadSuggestions() to support bulk affiliation loading
 * (an optimization that loads all affiliations for the current page at once).
 */
class Assistant extends AbstractAssistant
{
    public function __construct(
        private readonly OrcidDiscoveryService $service,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    /**
     * Override loadSuggestions to include affiliation bulk-loading.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[\Override]
    public function loadSuggestions(int $perPage): LengthAwarePaginator
    {
        $paginator = $this->query($perPage);

        // Bulk-load affiliations for all person IDs on the current page
        /** @var array<int, int> $personIds */
        $personIds = $paginator->getCollection()->pluck('person_id')->unique()->values()->all();

        /** @var array<int, array<int, string>> $affiliationCache */
        $affiliationCache = $this->service->loadPersonAffiliations($personIds);

        return $paginator->through(function (SuggestedOrcid $s) use ($affiliationCache): array {
            $person = $s->person;
            $personAffiliations = $affiliationCache[$s->person_id] ?? [];

            return [
                'id' => $s->id,
                'resource_id' => $s->resource_id,
                'resource_doi' => $s->resource->doi ?? '',
                'resource_title' => $s->resource->mainTitle ?? 'Untitled',
                'person_id' => $s->person_id,
                'person_name' => $person->full_name,
                'person_affiliations' => $personAffiliations,
                'source_context' => $s->source_context,
                'suggested_orcid' => $s->suggested_orcid,
                'similarity_score' => $s->similarity_score,
                'candidate_first_name' => $s->candidate_first_name ?? '',
                'candidate_last_name' => $s->candidate_last_name ?? '',
                'candidate_affiliations' => $s->candidate_affiliations ?? [],
                'discovered_at' => $s->discovered_at->toIso8601String(),
            ];
        });
    }

    #[\Override]
    protected function query(int $perPage): LengthAwarePaginator
    {
        $enrichableCounts = SuggestedOrcid::selectRaw('resource_id, COUNT(DISTINCT person_id) as enrichable_count')
            ->groupBy('resource_id');

        return SuggestedOrcid::with(['resource.titles.titleType', 'person'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_orcids.resource_id', '=', 'enrichable_counts.resource_id')
            ->select('suggested_orcids.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_orcids.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'orcid_page');
    }

    #[\Override]
    protected function transform(Model $suggestion): array
    {
        /** @var SuggestedOrcid $suggestion */
        return [
            'id' => $suggestion->id,
            'resource_id' => $suggestion->resource_id,
            'resource_doi' => $suggestion->resource->doi ?? '',
            'resource_title' => $suggestion->resource->mainTitle ?? 'Untitled',
            'person_id' => $suggestion->person_id,
            'person_name' => $suggestion->person->full_name,
            'person_affiliations' => [],
            'source_context' => $suggestion->source_context,
            'suggested_orcid' => $suggestion->suggested_orcid,
            'similarity_score' => $suggestion->similarity_score,
            'candidate_first_name' => $suggestion->candidate_first_name ?? '',
            'candidate_last_name' => $suggestion->candidate_last_name ?? '',
            'candidate_affiliations' => $suggestion->candidate_affiliations ?? [],
            'discovered_at' => $suggestion->discovered_at->toIso8601String(),
        ];
    }

    #[\Override]
    protected function findById(int $id): ?Model
    {
        return SuggestedOrcid::find($id);
    }

    #[\Override]
    public function countPending(): int
    {
        return SuggestedOrcid::count();
    }

    #[\Override]
    public function dispatchDiscovery(string $jobId, string $lockOwner): void
    {
        DiscoverOrcidsJob::dispatch($jobId, $lockOwner);
    }

    #[\Override]
    protected function accept(Model $suggestion): array
    {
        /** @var SuggestedOrcid $suggestion */
        return $this->service->acceptOrcid($suggestion);
    }

    #[\Override]
    protected function decline(Model $suggestion, User $user, ?string $reason): void
    {
        /** @var SuggestedOrcid $suggestion */
        $this->service->declineOrcid($suggestion, $user, $reason);
    }
}
