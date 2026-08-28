<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Services\Resources\ResourceImpactFilterService;
use App\Support\ResourceImpactFilter;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Builds resource-oriented review pages while keeping filtering, counting,
 * and pagination in the database. Only suggestions on the current page are
 * hydrated into presentation arrays.
 */
final class AssistanceReviewService
{
    public function __construct(
        private readonly AssistantRegistrar $registrar,
        private readonly ResourceImpactFilterService $filterService,
    ) {}

    /**
     * @return array{
     *     allAssistantResources: LengthAwarePaginator<int, array<string, mixed>>,
     *     sections: array<string, LengthAwarePaginator<int, array<string, mixed>>>,
     *     pendingCounts: array<string, int>,
     *     datacenterOptions: list<array{id: int, name: string}>
     * }
     */
    public function build(Request $request, int $perPage, ?ResourceImpactFilter $filter = null): array
    {
        $filter ??= new ResourceImpactFilter;
        $assistants = $this->registrar->getAll();
        $pendingImpacts = $this->pendingImpactQuery($assistants);
        $datacenterOptions = $this->datacenterOptions($pendingImpacts);
        $filteredImpacts = $this->filteredImpactQuery($pendingImpacts, $filter);
        $pendingCounts = $this->pendingCounts($filteredImpacts, array_keys($assistants));

        $allPaginator = $this->paginateResources(
            impacts: $filteredImpacts,
            assistants: $assistants,
            filter: $filter,
            datacenterOptions: $datacenterOptions,
            perPage: $perPage,
            pageName: 'all_page',
            request: $request,
        );

        $sections = [];

        foreach ($assistants as $assistantId => $assistant) {
            $sections[$assistantId] = $this->paginateResources(
                impacts: $filteredImpacts,
                assistants: [$assistantId => $assistant],
                filter: $filter,
                datacenterOptions: $datacenterOptions,
                perPage: $perPage,
                pageName: $assistantId.'_page',
                request: $request,
            );
        }

        return [
            'allAssistantResources' => $allPaginator,
            'sections' => $sections,
            'pendingCounts' => $pendingCounts,
            'datacenterOptions' => $datacenterOptions,
        ];
    }

    /**
     * Return the supplied resource IDs that are affected by at least one
     * currently pending suggestion from a registered assistant.
     *
     * @param  list<int>  $resourceIds
     * @return list<int>
     */
    public function resourceIdsWithPendingSuggestions(array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_filter(
            array_map(static fn (int $resourceId): int => $resourceId, $resourceIds),
            static fn (int $resourceId): bool => $resourceId > 0,
        )));

        if ($resourceIds === []) {
            return [];
        }

        $pendingImpacts = $this->pendingImpactQuery($this->registrar->getAll());

        /** @var list<int> $matchingResourceIds */
        $matchingResourceIds = DB::query()
            ->fromSub($pendingImpacts, 'pending_impacts')
            ->whereIn('pending_impacts.impact_resource_id', $resourceIds)
            ->distinct()
            ->orderBy('pending_impacts.impact_resource_id')
            ->pluck('pending_impacts.impact_resource_id')
            ->map(static fn (mixed $resourceId): int => (int) $resourceId)
            ->values()
            ->all();

        return $matchingResourceIds;
    }

    /**
     * @param  array<string, AssistantContract>  $assistants
     */
    private function pendingImpactQuery(array $assistants): QueryBuilder
    {
        $combined = null;

        foreach ($assistants as $assistant) {
            $query = DB::query()
                ->fromSub($assistant->pendingSuggestionImpactQuery(), 'assistant_impacts')
                ->select([
                    'assistant_impacts.assistant_id',
                    'assistant_impacts.suggestion_id',
                    'assistant_impacts.resource_id',
                    'assistant_impacts.impact_resource_id',
                    'assistant_impacts.resource_created_at',
                ]);

            if ($combined === null) {
                $combined = $query;
            } else {
                $combined->unionAll($query);
            }
        }

        $combined ??= DB::query()
            ->selectRaw('NULL AS assistant_id, NULL AS suggestion_id, NULL AS resource_id, NULL AS impact_resource_id, NULL AS resource_created_at')
            ->whereRaw('1 = 0');

        return DB::query()
            ->fromSub($combined, 'pending_impacts')
            ->select('pending_impacts.*');
    }

    private function filteredImpactQuery(QueryBuilder $pendingImpacts, ResourceImpactFilter $filter): QueryBuilder
    {
        $query = DB::query()
            ->fromSub(clone $pendingImpacts, 'pending_impacts')
            ->select('pending_impacts.*');

        if ($filter->isActive()) {
            $query->join('resources AS impact_resources', 'pending_impacts.impact_resource_id', '=', 'impact_resources.id');
            $this->filterService->apply($query, $filter, 'impact_resources');
        }

        return $query;
    }

    /**
     * @param  list<string>  $assistantIds
     * @return array<string, int>
     */
    private function pendingCounts(QueryBuilder $filteredImpacts, array $assistantIds): array
    {
        $counts = array_fill_keys($assistantIds, 0);
        $rows = DB::query()
            ->fromSub(clone $filteredImpacts, 'filtered_impacts')
            ->select('filtered_impacts.assistant_id')
            ->selectRaw('COUNT(DISTINCT filtered_impacts.suggestion_id) AS aggregate')
            ->groupBy('filtered_impacts.assistant_id')
            ->get();

        foreach ($rows as $row) {
            $assistantId = (string) $row->assistant_id;

            if (array_key_exists($assistantId, $counts)) {
                $counts[$assistantId] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, AssistantContract>  $assistants
     * @param  list<array{id: int, name: string}>  $datacenterOptions
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateResources(
        QueryBuilder $impacts,
        array $assistants,
        ResourceImpactFilter $filter,
        array $datacenterOptions,
        int $perPage,
        string $pageName,
        Request $request,
    ): LengthAwarePaginator {
        $assistantIds = array_keys($assistants);
        $resourceQuery = DB::query()
            ->fromSub(clone $impacts, 'filtered_impacts')
            ->select('filtered_impacts.resource_id')
            ->selectRaw('MAX(filtered_impacts.resource_created_at) AS resource_created_at')
            ->groupBy('filtered_impacts.resource_id')
            ->orderByDesc('resource_created_at')
            ->orderByDesc('filtered_impacts.resource_id');

        if ($assistantIds !== []) {
            $resourceQuery->whereIn('filtered_impacts.assistant_id', $assistantIds);
        } else {
            $resourceQuery->whereRaw('1 = 0');
        }

        $page = max(1, LengthAwarePaginator::resolveCurrentPage($pageName));
        $paginator = $resourceQuery->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: $pageName,
            page: $page,
        );
        /** @var list<int> $resourceIds */
        $resourceIds = array_values($paginator->getCollection()
            ->map(static fn (mixed $row): int => (int) data_get($row, 'resource_id'))
            ->values()
            ->all());
        $allowedSuggestions = $filter->isActive()
            ? $this->allowedSuggestions(
                impacts: $impacts,
                assistantIds: $assistantIds,
                resourceIds: $resourceIds,
                filter: $filter,
                datacenterOptions: $datacenterOptions,
            )
            : null;
        $groups = $this->hydrateResourceGroups($resourceIds, $assistants, $allowedSuggestions);

        $paginator->setCollection(collect($groups));
        $paginator->withPath($request->url());

        return $paginator->appends($request->query());
    }

    /**
     * @param  list<string>  $assistantIds
     * @param  list<int>  $resourceIds
     * @param  list<array{id: int, name: string}>  $datacenterOptions
     * @return array<string, array<int, array<string, mixed>|null>>
     */
    private function allowedSuggestions(
        QueryBuilder $impacts,
        array $assistantIds,
        array $resourceIds,
        ResourceImpactFilter $filter,
        array $datacenterOptions,
    ): array {
        if ($assistantIds === [] || $resourceIds === []) {
            return [];
        }

        $rows = DB::query()
            ->fromSub(clone $impacts, 'filtered_impacts')
            ->whereIn('filtered_impacts.assistant_id', $assistantIds)
            ->whereIn('filtered_impacts.resource_id', $resourceIds)
            ->select([
                'filtered_impacts.assistant_id',
                'filtered_impacts.suggestion_id',
                'filtered_impacts.resource_id',
            ])
            ->selectRaw('COUNT(DISTINCT filtered_impacts.impact_resource_id) AS matched_resource_count')
            ->selectRaw('MAX(CASE WHEN filtered_impacts.impact_resource_id = filtered_impacts.resource_id THEN 1 ELSE 0 END) AS origin_matches')
            ->groupBy([
                'filtered_impacts.assistant_id',
                'filtered_impacts.suggestion_id',
                'filtered_impacts.resource_id',
            ])
            ->get();
        $datacenterNames = [];

        foreach ($datacenterOptions as $option) {
            $datacenterNames[$option['id']] = $option['name'];
        }

        $allowed = [];

        foreach ($rows as $row) {
            $assistantId = (string) $row->assistant_id;
            $suggestionId = (int) $row->suggestion_id;
            $allowed[$assistantId][$suggestionId] = (int) $row->origin_matches === 1
                ? null
                : [
                    'kind' => 'indirect',
                    'matched_resource_count' => (int) $row->matched_resource_count,
                    'matched_doi' => $filter->doi,
                    'matched_datacenter_name' => $filter->datacenterId !== null
                        ? ($datacenterNames[$filter->datacenterId] ?? null)
                        : null,
                ];
        }

        return $allowed;
    }

    /**
     * @param  list<int>  $resourceIds
     * @param  array<string, AssistantContract>  $assistants
     * @param  array<string, array<int, array<string, mixed>|null>>|null  $allowedSuggestions
     * @return list<array<string, mixed>>
     */
    private function hydrateResourceGroups(array $resourceIds, array $assistants, ?array $allowedSuggestions): array
    {
        if ($resourceIds === []) {
            return [];
        }

        $groups = [];

        foreach ($resourceIds as $resourceId) {
            $groups[$resourceId] = [
                'resource_id' => $resourceId,
                'resource_doi' => '',
                'resource_title' => '',
                'suggestion_count' => 0,
                'suggestions' => [],
            ];
        }

        foreach ($assistants as $assistantId => $assistant) {
            $suggestionIds = $allowedSuggestions === null
                ? null
                : array_keys($allowedSuggestions[$assistantId] ?? []);

            if ($suggestionIds === []) {
                continue;
            }

            foreach ($assistant->loadSuggestionsForResources($resourceIds, $suggestionIds) as $suggestion) {
                $resourceId = (int) ($suggestion['resource_id'] ?? 0);
                $suggestionId = (int) ($suggestion['id'] ?? 0);

                if (! isset($groups[$resourceId])) {
                    continue;
                }

                if ($allowedSuggestions !== null && ! array_key_exists($suggestionId, $allowedSuggestions[$assistantId] ?? [])) {
                    continue;
                }

                $filterMatch = $allowedSuggestions[$assistantId][$suggestionId] ?? null;

                if (is_array($filterMatch)) {
                    $review = is_array($suggestion['review'] ?? null) ? $suggestion['review'] : [];
                    $review['filter_match'] = $filterMatch;
                    $suggestion['review'] = $review;
                }

                $doi = $suggestion['resource_doi'] ?? null;
                $title = $suggestion['resource_title'] ?? null;

                if ($groups[$resourceId]['resource_doi'] === '' && is_string($doi) && trim($doi) !== '') {
                    $groups[$resourceId]['resource_doi'] = trim($doi);
                }

                if ($groups[$resourceId]['resource_title'] === '' && is_string($title) && trim($title) !== '') {
                    $groups[$resourceId]['resource_title'] = trim($title);
                }

                $groups[$resourceId]['suggestions'][] = $suggestion;
            }
        }

        $ordered = [];

        foreach ($resourceIds as $resourceId) {
            $group = $groups[$resourceId];
            $group['suggestions'] = $this->normalizeSuggestionGroups($group['suggestions']);
            $group['suggestion_count'] = count($group['suggestions']);
            $ordered[] = $group;
        }

        return $ordered;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function datacenterOptions(QueryBuilder $pendingImpacts): array
    {
        $options = DB::query()
            ->fromSub(clone $pendingImpacts, 'pending_impacts')
            ->join('resources AS impact_resources', 'pending_impacts.impact_resource_id', '=', 'impact_resources.id')
            ->join('datacenters', 'impact_resources.datacenter_id', '=', 'datacenters.id')
            ->select([
                'datacenters.id',
                'datacenters.name',
            ])
            ->distinct()
            ->orderBy('datacenters.name')
            ->orderBy('datacenters.id')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->values()
            ->all();

        /** @var list<array{id: int, name: string}> $options */
        return $options;
    }

    /**
     * Keep alternatives for the same target adjacent and only mark groups that
     * actually contain more than one candidate.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @return list<array<string, mixed>>
     */
    private function normalizeSuggestionGroups(array $suggestions): array
    {
        $bucketOrder = [];
        $buckets = [];

        foreach ($suggestions as $index => $suggestion) {
            $review = is_array($suggestion['review'] ?? null) ? $suggestion['review'] : [];
            $targetKey = $review['exclusive_target_key'] ?? null;
            $assistantId = (string) ($review['assistant_id'] ?? $suggestion['assistant_id'] ?? '');
            $suggestionId = (int) ($suggestion['id'] ?? $index);
            $bucketKey = is_string($targetKey) && $targetKey !== ''
                ? 'exclusive:'.$targetKey
                : 'single:'.$assistantId.':'.$suggestionId;

            if (! isset($buckets[$bucketKey])) {
                $bucketOrder[] = $bucketKey;
                $buckets[$bucketKey] = [
                    'target_key' => is_string($targetKey) && $targetKey !== '' ? $targetKey : null,
                    'items' => [],
                ];
            }

            $buckets[$bucketKey]['items'][] = $suggestion;
        }

        $normalized = [];

        foreach ($bucketOrder as $bucketKey) {
            $bucket = $buckets[$bucketKey];
            $isExclusive = $bucket['target_key'] !== null && count($bucket['items']) > 1;

            if ($isExclusive) {
                usort($bucket['items'], static function (array $left, array $right): int {
                    $leftScore = is_numeric($left['similarity_score'] ?? null) ? (float) $left['similarity_score'] : -1.0;
                    $rightScore = is_numeric($right['similarity_score'] ?? null) ? (float) $right['similarity_score'] : -1.0;
                    $scoreOrder = $rightScore <=> $leftScore;

                    return $scoreOrder !== 0
                        ? $scoreOrder
                        : ((int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
                });
            }

            foreach ($bucket['items'] as $suggestion) {
                $review = is_array($suggestion['review'] ?? null) ? $suggestion['review'] : [];
                $review['exclusive_target_key'] = $isExclusive ? $bucket['target_key'] : null;
                $suggestion['review'] = $review;
                $normalized[] = $suggestion;
            }
        }

        return $normalized;
    }
}
