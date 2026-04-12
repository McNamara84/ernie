<?php

declare(strict_types=1);

namespace Modules\Assistants\RorSuggestion;

use App\Jobs\DiscoverRorsJob;
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
    public function __construct(
        private readonly RorDiscoveryService $service,
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
        $enrichableCounts = SuggestedRor::selectRaw('resource_id, COUNT(*) as enrichable_count')
            ->groupBy('resource_id');

        return SuggestedRor::with(['resource.titles.titleType'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_rors.resource_id', '=', 'enrichable_counts.resource_id')
            ->select('suggested_rors.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_rors.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'ror_page');
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
