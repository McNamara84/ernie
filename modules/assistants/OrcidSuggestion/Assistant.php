<?php

declare(strict_types=1);

namespace Modules\Assistants\OrcidSuggestion;

use App\Jobs\DiscoverOrcidsJob;
use App\Models\Person;
use App\Models\SuggestedOrcid;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\OrcidDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
        return __DIR__.'/manifest.json';
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

            $item = [
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

            return $this->presentTransformed($s, $item);
        });
    }

    #[\Override]
    protected function query(int $perPage): LengthAwarePaginator
    {
        $enrichableCounts = SuggestedOrcid::selectRaw('resource_id, COUNT(DISTINCT person_id) as enrichable_count')
            ->groupBy('resource_id');

        return SuggestedOrcid::with(['resource.titles.titleType', 'person'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_orcids.resource_id', '=', 'enrichable_counts.resource_id')
            ->join('resources', 'suggested_orcids.resource_id', '=', 'resources.id')
            ->select('suggested_orcids.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_orcids.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'orcid_page');
    }

    #[\Override]
    public function pendingSuggestionImpactQuery(): QueryBuilder
    {
        $direct = DB::table('suggested_orcids')
            ->join('resources', 'suggested_orcids.resource_id', '=', 'resources.id')
            ->select([
                'suggested_orcids.id AS suggestion_id',
                'suggested_orcids.resource_id AS resource_id',
                'suggested_orcids.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);

        $creatorImpacts = DB::table('suggested_orcids')
            ->join('resources', 'suggested_orcids.resource_id', '=', 'resources.id')
            ->join('resource_creators AS impact_creators', function (JoinClause $join): void {
                $join->on('impact_creators.creatorable_id', '=', 'suggested_orcids.person_id')
                    ->where('impact_creators.creatorable_type', Person::class);
            })
            ->select([
                'suggested_orcids.id AS suggestion_id',
                'suggested_orcids.resource_id AS resource_id',
                'impact_creators.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);

        $contributorImpacts = DB::table('suggested_orcids')
            ->join('resources', 'suggested_orcids.resource_id', '=', 'resources.id')
            ->join('resource_contributors AS impact_contributors', function (JoinClause $join): void {
                $join->on('impact_contributors.contributorable_id', '=', 'suggested_orcids.person_id')
                    ->where('impact_contributors.contributorable_type', Person::class);
            })
            ->select([
                'suggested_orcids.id AS suggestion_id',
                'suggested_orcids.resource_id AS resource_id',
                'impact_contributors.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);

        return $direct->union($creatorImpacts)->union($contributorImpacts);
    }

    #[\Override]
    public function loadSuggestionsForResources(array $resourceIds, ?array $suggestionIds = null): array
    {
        if ($resourceIds === [] || $suggestionIds === []) {
            return [];
        }

        $query = SuggestedOrcid::query()
            ->with(['resource.titles.titleType', 'person'])
            ->whereIn('suggested_orcids.resource_id', $resourceIds)
            ->join('resources', 'suggested_orcids.resource_id', '=', 'resources.id');

        if ($suggestionIds !== null) {
            $query->whereIn('suggested_orcids.id', $suggestionIds);
        }

        $suggestions = $query
            ->select('suggested_orcids.*')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('suggested_orcids.similarity_score')
            ->orderByDesc('suggested_orcids.id')
            ->get();

        /** @var array<int, int> $personIds */
        $personIds = $suggestions->pluck('person_id')->unique()->values()->all();
        $affiliationCache = $this->service->loadPersonAffiliations($personIds);

        return $suggestions
            ->map(function (SuggestedOrcid $suggestion) use ($affiliationCache): array {
                $item = $this->transform($suggestion);
                $item['person_affiliations'] = $affiliationCache[$suggestion->person_id] ?? [];

                return $this->presentTransformed($suggestion, $item);
            })
            ->values()
            ->all();
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
    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        /** @var SuggestedOrcid $suggestion */
        $metadata = parent::reviewMetadata($suggestion, $item);
        $metadata['exclusive_target_key'] = $this->getId().':person:'.$suggestion->person_id;

        return $metadata;
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
