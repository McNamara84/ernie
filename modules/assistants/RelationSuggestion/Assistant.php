<?php

declare(strict_types=1);

namespace Modules\Assistants\RelationSuggestion;

use App\Jobs\DiscoverRelationsJob;
use App\Models\SuggestedRelation;
use App\Models\User;
use App\Services\Assistance\AbstractAssistant;
use App\Services\RelationDiscoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

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
        return __DIR__ . '/manifest.json';
    }

    #[\Override]
    protected function query(int $perPage): LengthAwarePaginator
    {
        return SuggestedRelation::with(['resource.titles.titleType', 'identifierType', 'relationType'])
            ->orderBy('discovered_at', 'desc')
            ->paginate($perPage);
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

    #[\Override]
    protected function decline(Model $suggestion, User $user, ?string $reason): void
    {
        /** @var SuggestedRelation $suggestion */
        $this->service->declineRelation($suggestion, $user, $reason);
    }
}
