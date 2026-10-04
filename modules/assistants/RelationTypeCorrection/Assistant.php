<?php

declare(strict_types=1);

namespace Modules\Assistants\RelationTypeCorrection;

use App\Contracts\AcceptsDeclineInput;
use App\Contracts\ReportsDiscoveryDetails;
use App\Models\AssistantSuggestion;
use App\Models\User;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\RelationTypeCorrection\RelationCorrectionDiscoveryService;
use App\Services\RelationTypeCorrection\RelationCorrectionReviewService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

final class Assistant extends GenericTableAssistant implements AcceptsDeclineInput, ReportsDiscoveryDetails
{
    public function __construct(private readonly RelationCorrectionDiscoveryService $discovery, private readonly RelationCorrectionReviewService $review)
    {
        parent::__construct();
    }

    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    protected function discover(Closure $onProgress): int
    {
        return $this->discovery->discover($this->storeSuggestion(...), $onProgress);
    }

    public function discoveryDetails(): array
    {
        return $this->discovery->details();
    }

    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return ['success' => false, 'message' => 'Review the relation type preview before accepting.'];
    }

    protected function acceptWithInput(Model $suggestion, array $input): array
    {
        $actor = Auth::user();
        if (! $suggestion instanceof AssistantSuggestion || ! $actor instanceof User) {
            return ['success' => false, 'message' => 'An authenticated curator is required.'];
        }

        return $this->review->review($suggestion, $actor, 'accepted', $input);
    }

    /** @return array<string, mixed> */
    public function declineSuggestion(int $id, User $user, ?string $reason): array
    {
        return $this->declineSuggestionWithInput($id, $user, $reason, []);
    }

    public function declineSuggestionWithInput(int $id, User $user, ?string $reason, array $input): array
    {
        $suggestion = $this->live()->find($id);

        return $suggestion === null ? ['success' => false, 'message' => 'Suggestion not found.']
            : $this->review->review($suggestion, $user, 'declined', $input, $reason);
    }

    /** @return Builder<AssistantSuggestion> */
    private function live(): Builder
    {
        return AssistantSuggestion::where('assistant_id', $this->getId())->where('target_type', 'related_identifier')
            ->whereExists($this->liveTarget(...));
    }

    private function liveTarget(QueryBuilder $query): void
    {
        $query->selectRaw('1')->from('related_identifiers')->whereColumn('related_identifiers.id', 'assistant_suggestions.target_id')
            ->whereColumn('related_identifiers.resource_id', 'assistant_suggestions.resource_id');
    }

    protected function query(int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Model> */
        return $this->live()->with('resource.titles.titleType')->join('resources', 'assistant_suggestions.resource_id', '=', 'resources.id')
            ->select('assistant_suggestions.*')->orderByDesc('resources.created_at')->orderByDesc('assistant_suggestions.id')
            ->paginate($perPage, ['*'], $this->getId().'_page');
    }

    public function pendingSuggestionQuery(): QueryBuilder
    {
        return parent::pendingSuggestionQuery()->where('target_type', 'related_identifier')->whereExists($this->liveTarget(...));
    }

    public function pendingResourceImpactQuery(): QueryBuilder
    {
        return parent::pendingResourceImpactQuery()->where('target_type', 'related_identifier')->whereExists($this->liveTarget(...));
    }

    public function loadSuggestionsForResources(array $resourceIds, ?array $suggestionIds = null): array
    {
        if ($resourceIds === [] || $suggestionIds === []) {
            return [];
        }

        return array_values($this->live()->with('resource.titles.titleType')->whereIn('resource_id', $resourceIds)
            ->when($suggestionIds !== null, fn ($query) => $query->whereIn('id', $suggestionIds))
            ->orderByDesc('id')->get()->map(fn (AssistantSuggestion $suggestion): array => $this->present($suggestion))->all());
    }

    protected function findById(int $id): ?Model
    {
        return $this->live()->find($id);
    }

    public function countPending(): int
    {
        return $this->live()->count();
    }

    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        return ['can_accept' => true, 'can_decline' => true,
            'exclusive_target_key' => $this->getId().':related_identifier:'.$suggestion->getAttribute('target_id'),
            'label' => (string) ($item['suggested_label'] ?? 'Review relation type')];
    }
}
