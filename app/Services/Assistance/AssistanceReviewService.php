<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Builds complete, resource-oriented review pages for the Assistance UI.
 */
final class AssistanceReviewService
{
    public function __construct(
        private readonly AssistantRegistrar $registrar,
    ) {}

    /**
     * @return array{
     *     allAssistantResources: LengthAwarePaginator<int, array<string, mixed>>,
     *     sections: array<string, LengthAwarePaginator<int, array<string, mixed>>>,
     *     pendingCounts: array<string, int>
     * }
     */
    public function build(Request $request, int $perPage): array
    {
        $assistants = $this->registrar->getAll();
        $allResources = [];
        $assistantResources = [];
        $pendingCounts = [];

        foreach ($assistants as $assistantId => $assistant) {
            $resources = $assistant->listPendingResources();
            $assistantResources[$assistantId] = $resources;
            $pendingCounts[$assistantId] = $assistant->countPending();

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
            perPage: $perPage,
            pageName: 'all_page',
            request: $request,
        );

        $sections = [];

        foreach ($assistants as $assistantId => $assistant) {
            $sections[$assistantId] = $this->paginateResources(
                resources: $this->sortResources($assistantResources[$assistantId]),
                assistants: [$assistantId => $assistant],
                perPage: $perPage,
                pageName: $assistantId.'_page',
                request: $request,
            );
        }

        return [
            'allAssistantResources' => $allPaginator,
            'sections' => $sections,
            'pendingCounts' => $pendingCounts,
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
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateResources(
        array $resources,
        array $assistants,
        int $perPage,
        string $pageName,
        Request $request,
    ): LengthAwarePaginator {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage($pageName));
        $pageResources = array_slice($resources, ($page - 1) * $perPage, $perPage);
        $resourceIds = array_column($pageResources, 'resource_id');
        $groups = $this->hydrateResourceGroups($resourceIds, $assistants);

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
     * @return list<array<string, mixed>>
     */
    private function hydrateResourceGroups(array $resourceIds, array $assistants): array
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

        foreach ($assistants as $assistant) {
            foreach ($assistant->loadSuggestionsForResources($resourceIds) as $suggestion) {
                $resourceId = (int) ($suggestion['resource_id'] ?? 0);

                if (! isset($groups[$resourceId])) {
                    continue;
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
