<?php

declare(strict_types=1);

namespace Modules\Assistants\RelationSuggestion;

use App\Jobs\DiscoverRelationsJob;
use App\Models\SuggestedRelation;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\RelationDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Assistant module for discovering related works via external APIs.
 *
 * Wraps the existing RelationDiscoveryService and DiscoverRelationsJob.
 * Uses the existing suggested_relations / dismissed_relations tables.
 */
class Assistant extends AbstractAssistant
{
    public function __construct(
        private readonly RelationDiscoveryService $service,
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
        return SuggestedRelation::with(['resource.titles.titleType', 'identifierType', 'relationType'])
            ->join('resources', 'suggested_relations.resource_id', '=', 'resources.id')
            ->select('suggested_relations.*')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('suggested_relations.discovered_at')
            ->paginate($perPage, ['*'], 'relation_page');
    }

    #[\Override]
    public function pendingSuggestionImpactQuery(): QueryBuilder
    {
        return DB::table('suggested_relations')
            ->join('resources', 'suggested_relations.resource_id', '=', 'resources.id')
            ->select([
                'suggested_relations.id AS suggestion_id',
                'suggested_relations.resource_id AS resource_id',
                'suggested_relations.resource_id AS impact_resource_id',
                'resources.created_at AS resource_created_at',
            ])
            ->selectRaw('? AS assistant_id', [$this->getId()]);
    }

    #[\Override]
    public function loadSuggestionsForResources(array $resourceIds, ?array $suggestionIds = null): array
    {
        if ($resourceIds === [] || $suggestionIds === []) {
            return [];
        }

        $query = SuggestedRelation::query()
            ->with(['resource.titles.titleType', 'identifierType', 'relationType'])
            ->whereIn('suggested_relations.resource_id', $resourceIds)
            ->join('resources', 'suggested_relations.resource_id', '=', 'resources.id');

        if ($suggestionIds !== null) {
            $query->whereIn('suggested_relations.id', $suggestionIds);
        }

        return $query
            ->select('suggested_relations.*')
            ->orderByDesc('resources.created_at')
            ->orderByDesc('suggested_relations.discovered_at')
            ->orderByDesc('suggested_relations.id')
            ->get()
            ->map(fn (SuggestedRelation $suggestion): array => $this->present($suggestion))
            ->values()
            ->all();
    }

    #[\Override]
    protected function transform(Model $suggestion): array
    {
        /** @var SuggestedRelation $suggestion */
        return [
            'id' => $suggestion->id,
            'resource_id' => $suggestion->resource_id,
            'resource_doi' => $suggestion->resource->doi ?? '',
            'resource_title' => $suggestion->resource->mainTitle ?? 'Untitled',
            'identifier' => $suggestion->identifier,
            'identifier_type' => $suggestion->identifierType->slug ?? '',
            'identifier_type_name' => $suggestion->identifierType->name ?? '',
            'relation_type_id' => $suggestion->relation_type_id,
            'relation_type' => $suggestion->relationType->slug ?? '',
            'relation_type_name' => $suggestion->relationType->name ?? '',
            'source' => $suggestion->source,
            'source_title' => $suggestion->source_title,
            'source_type' => $suggestion->source_type,
            'source_publisher' => $suggestion->source_publisher,
            'source_publication_date' => $suggestion->source_publication_date,
            'discovered_at' => $suggestion->discovered_at->toIso8601String(),
        ];
    }

    #[\Override]
    protected function findById(int $id): ?Model
    {
        return SuggestedRelation::find($id);
    }

    #[\Override]
    public function countPending(): int
    {
        return SuggestedRelation::count();
    }

    #[\Override]
    public function dispatchDiscovery(string $jobId, string $lockOwner): void
    {
        DiscoverRelationsJob::dispatch($jobId, $lockOwner);
    }

    #[\Override]
    protected function accept(Model $suggestion): array
    {
        /** @var SuggestedRelation $suggestion */
        return $this->service->acceptRelation($suggestion);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[\Override]
    protected function acceptWithInput(Model $suggestion, array $input): array
    {
        /** @var SuggestedRelation $suggestion */
        $relationTypeId = $input['relation_type_id'] ?? null;

        if (is_string($relationTypeId)) {
            $relationTypeId = filter_var($relationTypeId, FILTER_VALIDATE_INT);
        }

        if ($relationTypeId !== null && ! is_int($relationTypeId)) {
            return [
                'success' => false,
                'datacite_synced' => false,
                'message' => 'The selected relation type is invalid.',
            ];
        }

        return $this->service->acceptRelation($suggestion, $relationTypeId);
    }

    #[\Override]
    protected function decline(Model $suggestion, User $user, ?string $reason): void
    {
        /** @var SuggestedRelation $suggestion */
        $this->service->declineRelation($suggestion, $user, $reason);
    }
}
