<?php

declare(strict_types=1);

namespace Modules\Assistants\RorSuggestion;

use App\Jobs\DiscoverRorsJob;
use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\RorDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

    #[\Override]
    public function pendingSuggestionImpactQuery(): QueryBuilder
    {
        $direct = DB::table('suggested_rors')
            ->join('resources', 'suggested_rors.resource_id', '=', 'resources.id')
            ->select([
                'suggested_rors.id AS suggestion_id',
                'suggested_rors.resource_id AS resource_id',
                'suggested_rors.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);

        $institutionCreatorImpacts = $this->institutionImpactQuery(
            table: 'resource_creators',
            alias: 'impact_creators',
            typeColumn: 'creatorable_type',
            idColumn: 'creatorable_id',
        );
        $institutionContributorImpacts = $this->institutionImpactQuery(
            table: 'resource_contributors',
            alias: 'impact_contributors',
            typeColumn: 'contributorable_type',
            idColumn: 'contributorable_id',
        );
        $affiliationCreatorImpacts = $this->affiliationImpactQuery(
            table: 'resource_creators',
            alias: 'impact_creators',
            affiliatableType: ResourceCreator::class,
        );
        $affiliationContributorImpacts = $this->affiliationImpactQuery(
            table: 'resource_contributors',
            alias: 'impact_contributors',
            affiliatableType: ResourceContributor::class,
        );

        return $direct
            ->union($institutionCreatorImpacts)
            ->union($institutionContributorImpacts)
            ->union($affiliationCreatorImpacts)
            ->union($affiliationContributorImpacts);
    }

    #[\Override]
    public function loadSuggestionsForResources(array $resourceIds, ?array $suggestionIds = null): array
    {
        if ($resourceIds === [] || $suggestionIds === []) {
            return [];
        }

        $query = SuggestedRor::query()
            ->with(['resource.titles.titleType'])
            ->whereIn('suggested_rors.resource_id', $resourceIds)
            ->join('resources', 'suggested_rors.resource_id', '=', 'resources.id');

        if ($suggestionIds !== null) {
            $query->whereIn('suggested_rors.id', $suggestionIds);
        }

        $suggestions = $query
            ->select('suggested_rors.*')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('suggested_rors.similarity_score')
            ->orderByDesc('suggested_rors.id')
            ->get();

        $this->affiliationPersonNames = $this->loadAffiliationPersonNames($suggestions);

        return $suggestions
            ->map(fn (SuggestedRor $suggestion): array => $this->present($suggestion))
            ->values()
            ->all();
    }

    private function institutionImpactQuery(string $table, string $alias, string $typeColumn, string $idColumn): QueryBuilder
    {
        return DB::table('suggested_rors')
            ->join('resources', 'suggested_rors.resource_id', '=', 'resources.id')
            ->join($table.' AS '.$alias, function (JoinClause $join) use ($alias, $typeColumn, $idColumn): void {
                $join->on($alias.'.'.$idColumn, '=', 'suggested_rors.entity_id')
                    ->where($alias.'.'.$typeColumn, Institution::class);
            })
            ->where('suggested_rors.entity_type', 'institution')
            ->select([
                'suggested_rors.id AS suggestion_id',
                'suggested_rors.resource_id AS resource_id',
                $alias.'.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);
    }

    private function affiliationImpactQuery(string $table, string $alias, string $affiliatableType): QueryBuilder
    {
        return DB::table('suggested_rors')
            ->join('resources', 'suggested_rors.resource_id', '=', 'resources.id')
            ->join('affiliations AS impact_affiliations', function (JoinClause $join): void {
                $join->on('impact_affiliations.id', '=', 'suggested_rors.entity_id');
            })
            ->join($table.' AS '.$alias, function (JoinClause $join) use ($alias, $affiliatableType): void {
                $join->on($alias.'.id', '=', 'impact_affiliations.affiliatable_id')
                    ->where('impact_affiliations.affiliatable_type', $affiliatableType);
            })
            ->where('suggested_rors.entity_type', 'affiliation')
            ->select([
                'suggested_rors.id AS suggestion_id',
                'suggested_rors.resource_id AS resource_id',
                $alias.'.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);
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
            fn (Model $model) => $this->present($model),
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
            'locations' => $suggestion->locations ?? [],
            'existing_identifier' => $suggestion->existing_identifier,
            'existing_identifier_type' => $suggestion->existing_identifier_type,
            'discovered_at' => $suggestion->discovered_at->toIso8601String(),
        ];
    }

    #[\Override]
    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        /** @var SuggestedRor $suggestion */
        $metadata = parent::reviewMetadata($suggestion, $item);
        $metadata['exclusive_target_key'] = $this->getId().':'.$suggestion->entity_type.':'.$suggestion->entity_id;

        return $metadata;
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
