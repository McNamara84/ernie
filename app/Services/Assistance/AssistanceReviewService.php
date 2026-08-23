<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Models\Datacenter;
use App\Models\Resource;
use App\Services\DoiSuggestionService;
use App\Support\ResourceImpactFilter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Builds complete, resource-oriented review pages for the Assistance UI.
 */
final class AssistanceReviewService
{
    public function __construct(
        private readonly AssistantRegistrar $registrar,
        private readonly DoiSuggestionService $doiService,
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
        $allResources = [];
        $assistantResources = [];
        $pendingCounts = [];
        $referencesByAssistant = [];
        $allImpactedResourceIds = [];

        foreach ($assistants as $assistantId => $assistant) {
            $references = $assistant->listPendingSuggestionReferences();
            $referencesByAssistant[$assistantId] = $references;

            foreach ($references as $reference) {
                array_push($allImpactedResourceIds, ...$reference['impacted_resource_ids']);
            }
        }

        $impactResources = $this->loadImpactResources($allImpactedResourceIds);
        $matchingResourceIds = $this->matchingResourceIds($impactResources, $filter, $allImpactedResourceIds);
        $allowedSuggestions = [];

        foreach ($assistants as $assistantId => $assistant) {
            $resources = [];
            $seenResourceIds = [];
            $pendingCounts[$assistantId] = 0;
            $allowedSuggestions[$assistantId] = [];

            foreach ($referencesByAssistant[$assistantId] as $reference) {
                $matchedResourceIds = array_values(array_intersect(
                    $reference['impacted_resource_ids'],
                    $matchingResourceIds,
                ));

                if ($matchedResourceIds === []) {
                    continue;
                }

                $pendingCounts[$assistantId]++;
                $resourceId = $reference['resource_id'];
                $allowedSuggestions[$assistantId][$reference['suggestion_id']] = $this->filterMatchMetadata(
                    originResourceId: $resourceId,
                    matchedResourceIds: $matchedResourceIds,
                    resources: $impactResources,
                    filter: $filter,
                );

                if (isset($seenResourceIds[$resourceId])) {
                    continue;
                }

                $seenResourceIds[$resourceId] = true;
                $resources[] = [
                    'resource_id' => $resourceId,
                    'resource_created_at_timestamp' => $reference['resource_created_at_timestamp'],
                ];
            }

            $assistantResources[$assistantId] = $resources;

            foreach ($resources as $resource) {
                $resourceId = $resource['resource_id'];
                $existing = $allResources[$resourceId] ?? null;

                if ($existing === null || $resource['resource_created_at_timestamp'] > $existing['resource_created_at_timestamp']) {
                    $allResources[$resourceId] = $resource;
                }
            }
        }

        $allRows = $this->sortResources(array_values($allResources));
        $allPaginator = $this->paginateResources(
            resources: $allRows,
            assistants: $assistants,
            allowedSuggestions: $allowedSuggestions,
            perPage: $perPage,
            pageName: 'all_page',
            request: $request,
        );

        $sections = [];

        foreach ($assistants as $assistantId => $assistant) {
            $sections[$assistantId] = $this->paginateResources(
                resources: $this->sortResources($assistantResources[$assistantId]),
                assistants: [$assistantId => $assistant],
                allowedSuggestions: [$assistantId => $allowedSuggestions[$assistantId]],
                perPage: $perPage,
                pageName: $assistantId.'_page',
                request: $request,
            );
        }

        return [
            'allAssistantResources' => $allPaginator,
            'sections' => $sections,
            'pendingCounts' => $pendingCounts,
            'datacenterOptions' => $this->datacenterOptions($impactResources),
        ];
    }

    /**
     * @param  list<array{resource_id: int, resource_created_at_timestamp: int}>  $resources
     * @return list<array{resource_id: int, resource_created_at_timestamp: int}>
     */
    private function sortResources(array $resources): array
    {
        usort($resources, static function (array $left, array $right): int {
            $dateOrder = $right['resource_created_at_timestamp'] <=> $left['resource_created_at_timestamp'];

            return $dateOrder !== 0
                ? $dateOrder
                : $right['resource_id'] <=> $left['resource_id'];
        });

        return $resources;
    }

    /**
     * @param  list<array{resource_id: int, resource_created_at_timestamp: int}>  $resources
     * @param  array<string, AssistantContract>  $assistants
     * @param  array<string, array<int, array<string, mixed>|null>>  $allowedSuggestions
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateResources(
        array $resources,
        array $assistants,
        array $allowedSuggestions,
        int $perPage,
        string $pageName,
        Request $request,
    ): LengthAwarePaginator {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage($pageName));
        $pageResources = array_slice($resources, ($page - 1) * $perPage, $perPage);
        $resourceIds = array_column($pageResources, 'resource_id');
        $groups = $this->hydrateResourceGroups($resourceIds, $assistants, $allowedSuggestions);

        $paginator = new LengthAwarePaginator(
            items: $groups,
            total: count($resources),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'pageName' => $pageName,
            ],
        );

        return $paginator->appends($request->query());
    }

    /**
     * @param  list<int>  $resourceIds
     * @param  array<string, AssistantContract>  $assistants
     * @param  array<string, array<int, array<string, mixed>|null>>  $allowedSuggestions
     * @return list<array<string, mixed>>
     */
    private function hydrateResourceGroups(array $resourceIds, array $assistants, array $allowedSuggestions): array
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
            foreach ($assistant->loadSuggestionsForResources($resourceIds) as $suggestion) {
                $resourceId = (int) ($suggestion['resource_id'] ?? 0);
                $suggestionId = (int) ($suggestion['id'] ?? 0);

                if (! isset($groups[$resourceId]) || ! array_key_exists($suggestionId, $allowedSuggestions[$assistantId] ?? [])) {
                    continue;
                }

                $filterMatch = $allowedSuggestions[$assistantId][$suggestionId];

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
     * @param  array<int, int>  $resourceIds
     * @return array<int, Resource>
     */
    private function loadImpactResources(array $resourceIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $resourceIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        return Resource::query()
            ->with('datacenter:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'doi', 'datacenter_id'])
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, Resource>  $resources
     * @param  array<int, int>  $allImpactedResourceIds
     * @return list<int>
     */
    private function matchingResourceIds(array $resources, ResourceImpactFilter $filter, array $allImpactedResourceIds): array
    {
        if (! $filter->isActive()) {
            return array_values(array_unique(array_map('intval', $allImpactedResourceIds)));
        }

        $matches = [];

        foreach ($resources as $resource) {
            if ($filter->doi !== null) {
                $resourceDoi = is_string($resource->doi) ? $this->doiService->normalizeDoi($resource->doi) : null;

                if ($resourceDoi !== $filter->doi) {
                    continue;
                }
            }

            if ($filter->datacenterId !== null && $resource->datacenter_id !== $filter->datacenterId) {
                continue;
            }

            $matches[] = $resource->id;
        }

        return $matches;
    }

    /**
     * @param  list<int>  $matchedResourceIds
     * @param  array<int, Resource>  $resources
     * @return array<string, mixed>|null
     */
    private function filterMatchMetadata(
        int $originResourceId,
        array $matchedResourceIds,
        array $resources,
        ResourceImpactFilter $filter,
    ): ?array {
        if (! $filter->isActive() || in_array($originResourceId, $matchedResourceIds, true)) {
            return null;
        }

        $firstMatch = $resources[$matchedResourceIds[0]] ?? null;

        return [
            'kind' => 'indirect',
            'matched_resource_count' => count($matchedResourceIds),
            'matched_doi' => $filter->doi,
            'matched_datacenter_name' => $filter->datacenterId !== null ? $firstMatch?->datacenter?->name : null,
        ];
    }

    /**
     * @param  array<int, Resource>  $resources
     * @return list<array{id: int, name: string}>
     */
    private function datacenterOptions(array $resources): array
    {
        $options = [];

        foreach ($resources as $resource) {
            $datacenter = $resource->datacenter;

            if (! $datacenter instanceof Datacenter) {
                continue;
            }

            $options[$datacenter->id] = [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
            ];
        }

        uasort($options, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return array_values($options);
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
