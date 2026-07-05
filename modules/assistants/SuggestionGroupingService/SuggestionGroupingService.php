<?php

namespace App\Services\Assistance;

use Illuminate\Support\Collection;

class SuggestionGroupingService
{
    /**
     * Group all assistant suggestions by resource/file.
     *
     * Normal suggestions can be used for checkbox bulk actions.
     * Choice suggestions are separated because they require an explicit either/or decision.
     */
    public function groupByResource(array $sections): Collection
    {
        $grouped = collect();

        foreach ($sections as $assistantId => $paginatedSuggestions) {
            $suggestions = collect($paginatedSuggestions['data'] ?? []);

            foreach ($suggestions as $suggestion) {
                $resourceId = $suggestion['resource_id'] ?? null;

                if ($resourceId === null) {
                    continue;
                }

                if (! $grouped->has($resourceId)) {
                    $grouped->put($resourceId, [
                        'resource_id' => $resourceId,
                        'doi' => $suggestion['doi'] ?? null,
                        'title' => $suggestion['title'] ?? null,
                        'normal_suggestions' => [],
                        'choice_groups' => [],
                    ]);
                }

                $resource = $grouped->get($resourceId);

                $suggestion['assistant_id'] = $assistantId;

                if (($suggestion['type'] ?? 'normal') === 'choice') {
                    $choiceGroupId = $suggestion['choice_group_id'] ?? 'default';

                    $resource['choice_groups'][$choiceGroupId]['choice_group_id'] = $choiceGroupId;
                    $resource['choice_groups'][$choiceGroupId]['options'][] = $suggestion;
                } else {
                    $resource['normal_suggestions'][] = $suggestion;
                }

                $grouped->put($resourceId, $resource);
            }
        }

        return $grouped
            ->map(function (array $resource) {
                $resource['choice_groups'] = array_values($resource['choice_groups']);

                return $resource;
            })
            ->values();
    }
}