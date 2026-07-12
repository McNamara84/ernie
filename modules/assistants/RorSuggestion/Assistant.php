<?php

declare(strict_types=1);

namespace Modules\Assistants\RorSuggestion;

use App\Jobs\DiscoverRorsJob;
use App\Models\Affiliation;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\RorDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Assistant module for discovering ROR identifiers for entities without one.
 *
 * Wraps the existing RorDiscoveryService and DiscoverRorsJob.
 * Uses the existing suggested_rors / dismissed_rors tables.
 */
class Assistant extends AbstractAssistant
{
    /** @var array<int, string> */
    private array $affiliationPersonNames = [];

    public function __construct(
        private readonly RorDiscoveryService $service,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    #[\Override]
    protected function query(int $perPage): LengthAwarePaginator
    {
        $enrichableCounts = SuggestedRor::selectRaw('resource_id, COUNT(*) as enrichable_count')
            ->groupBy('resource_id');

        return SuggestedRor::with(['resource.titles.titleType'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_rors.resource_id', '=', 'enrichable_counts.resource_id')
            ->join('resources', 'suggested_rors.resource_id', '=', 'resources.id')
            ->select('suggested_rors.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_rors.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'ror_page');
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[\Override]
    public function loadSuggestions(int $perPage): LengthAwarePaginator
    {
        $paginator = $this->query($perPage);
        $this->affiliationPersonNames = $this->loadAffiliationPersonNames($paginator->getCollection());

        return $paginator->through(
            fn (Model $model) => $this->transform($model),
        );
    }

    #[\Override]
    protected function transform(Model $suggestion): array
    {
        /** @var SuggestedRor $suggestion */
        return [
            'id' => $suggestion->id,
            'resource_id' => $suggestion->resource_id,
            'resource_doi' => $suggestion->resource->doi ?? '',
            'resource_title' => $suggestion->resource->mainTitle ?? 'Untitled',
            'entity_type' => $suggestion->entity_type,
            'entity_id' => $suggestion->entity_id,
            'entity_name' => $suggestion->entity_name,
            'person_name' => $suggestion->entity_type === 'affiliation'
                ? ($this->affiliationPersonNames[(int) $suggestion->entity_id] ?? null)
                : null,
            'suggested_ror_id' => $suggestion->suggested_ror_id,
            'suggested_name' => $suggestion->suggested_name,
            'similarity_score' => $suggestion->similarity_score,
            'ror_aliases' => $suggestion->ror_aliases ?? [],
            'existing_identifier' => $suggestion->existing_identifier,
            'existing_identifier_type' => $suggestion->existing_identifier_type,
            'discovered_at' => $suggestion->discovered_at->toIso8601String(),
        ];
    }

    #[\Override]
    protected function findById(int $id): ?Model
    {
        return SuggestedRor::find($id);
    }

    /**
     * @param  iterable<int, Model>  $suggestions
     * @return array<int, string>
     */
    private function loadAffiliationPersonNames(iterable $suggestions): array
    {
        $affiliationIds = [];

        foreach ($suggestions as $suggestion) {
            if ($suggestion instanceof SuggestedRor && $suggestion->entity_type === 'affiliation') {
                $affiliationIds[] = (int) $suggestion->entity_id;
            }
        }

        if ($affiliationIds === []) {
            return [];
        }

        $affiliations = Affiliation::query()
            ->whereIn('id', array_values(array_unique($affiliationIds)))
            ->get()
            ->keyBy('id');

        if ($affiliations->isEmpty()) {
            return [];
        }

        $creatorIds = [];
        $contributorIds = [];

        foreach ($affiliations as $affiliation) {
            if ($affiliation->affiliatable_type === ResourceCreator::class) {
                $creatorIds[] = (int) $affiliation->affiliatable_id;
            }

            if ($affiliation->affiliatable_type === ResourceContributor::class) {
                $contributorIds[] = (int) $affiliation->affiliatable_id;
            }
        }

        $creators = ResourceCreator::with('creatorable')
            ->whereIn('id', array_values(array_unique($creatorIds)))
            ->get()
            ->keyBy('id');

        $contributors = ResourceContributor::with('contributorable')
            ->whereIn('id', array_values(array_unique($contributorIds)))
            ->get()
            ->keyBy('id');

        $personNames = [];

        foreach ($affiliations as $affiliation) {
            $person = match ($affiliation->affiliatable_type) {
                ResourceCreator::class => $creators->get($affiliation->affiliatable_id)?->creatorable,
                ResourceContributor::class => $contributors->get($affiliation->affiliatable_id)?->contributorable,
                default => null,
            };

            if ($person instanceof Person && $person->full_name !== '') {
                $personNames[(int) $affiliation->id] = $person->full_name;
            }
        }

        return $personNames;
    }

    #[\Override]
    public function countPending(): int
    {
        return SuggestedRor::count();
    }

    #[\Override]
    public function dispatchDiscovery(string $jobId, string $lockOwner): void
    {
        DiscoverRorsJob::dispatch($jobId, $lockOwner);
    }

    #[\Override]
    protected function accept(Model $suggestion): array
    {
        /** @var SuggestedRor $suggestion */
        return $this->service->acceptRor($suggestion);
    }

    #[\Override]
    protected function decline(Model $suggestion, User $user, ?string $reason): void
    {
        /** @var SuggestedRor $suggestion */
        $this->service->declineRor($suggestion, $user, $reason);
    }
}
