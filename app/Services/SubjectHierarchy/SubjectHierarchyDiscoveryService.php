<?php

declare(strict_types=1);

namespace App\Services\SubjectHierarchy;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use Closure;
use Throwable;

final class SubjectHierarchyDiscoveryService
{
    public const string ASSISTANT_ID = 'subject-hierarchy-correction';

    /** @var array{checked_resources: int, subjects_unresolved: int, new_suggestions: int, resources_with_suggestions: int, stale_suggestions_removed: int, unavailable_vocabularies: string} */
    private array $report = ['checked_resources' => 0, 'subjects_unresolved' => 0, 'new_suggestions' => 0, 'resources_with_suggestions' => 0, 'stale_suggestions_removed' => 0, 'unavailable_vocabularies' => ''];

    public function __construct(private readonly SubjectHierarchyVocabularyService $vocabulary) {}

    /** @return array<string, int|string|bool|null> */
    public function report(): array
    {
        return $this->report;
    }

    /** @param Closure(int, string, array<string, mixed>): bool $store
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $store, Closure $onProgress): int
    {
        $this->vocabulary->reset();
        $this->report = ['checked_resources' => 0, 'subjects_unresolved' => 0, 'new_suggestions' => 0, 'resources_with_suggestions' => 0, 'stale_suggestions_removed' => 0, 'unavailable_vocabularies' => ''];
        $unavailable = [];
        Resource::query()->whereHas('subjects', fn ($query) => $query->controlled())->with('subjects')
            ->chunkById(100, function ($resources) use ($store, $onProgress, &$unavailable): void {
                foreach ($resources as $resource) {
                    $this->vocabulary->resetResolutions();
                    $this->report['checked_resources']++;
                    $seen = [];
                    $current = [];
                    foreach ($resource->subjects as $subject) {
                        $scheme = $this->vocabulary->scheme($subject);
                        if ($scheme === null || isset($unavailable[$scheme])) {
                            continue;
                        }
                        try {
                            $graph = $this->vocabulary->graph($scheme);
                        } catch (Throwable $exception) {
                            $unavailable[$scheme] = $exception->getMessage();
                            $onProgress($scheme.': '.$exception->getMessage());

                            continue;
                        }
                        $broaderId = $this->vocabulary->resolve($subject, $graph);
                        if ($broaderId === null) {
                            $this->report['subjects_unresolved']++;

                            continue;
                        }
                        $key = $scheme.'|'.$broaderId;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $concept = $graph->concept($broaderId);
                        $correction = $concept !== null && ! $concept['selectable']
                            ? ['scheme' => $scheme, 'broader_id' => $broaderId, 'broader_label' => $concept['label'], 'suggestion_kind' => 'hint',
                                'fingerprint' => hash('sha256', json_encode($concept, JSON_THROW_ON_ERROR)), 'nodes' => [], 'leaf_ids' => [], 'existing_leaf_ids' => [],
                                'reason' => 'This is a navigation group, not an indexable subject concept. Review its replacement in the editor.']
                            : $this->vocabulary->correction($resource->subjects, $graph, $broaderId);
                        if ($correction === null) {
                            continue;
                        }
                        $value = hash('sha256', $key).':'.$correction['fingerprint'];
                        $current[] = $value;
                        if ($store($resource->id, $value, $correction)) {
                            $this->report['new_suggestions']++;
                        }
                    }
                    // Reconcile only schemes whose local source was successfully checked.
                    $pending = AssistantSuggestion::where('assistant_id', self::ASSISTANT_ID)->where('resource_id', $resource->id)->get();
                    foreach ($pending as $suggestion) {
                        $metadata = $suggestion->metadata ?? [];
                        $scheme = $metadata['scheme'] ?? null;
                        if (is_string($scheme) && ! isset($unavailable[$scheme]) && ! in_array($suggestion->suggested_value, $current, true)) {
                            $suggestion->delete();
                            $this->report['stale_suggestions_removed']++;
                        }
                    }
                    if ($current !== []) {
                        $this->report['resources_with_suggestions']++;
                    }
                }
                $onProgress('Checked '.$this->report['checked_resources'].' resources.');
            });
        // Resources whose last controlled subject was removed no longer enter the scan.
        $this->report['stale_suggestions_removed'] += AssistantSuggestion::where('assistant_id', self::ASSISTANT_ID)
            ->whereHas('resource', fn ($query) => $query->whereDoesntHave('subjects', fn ($subjects) => $subjects->controlled()))->delete();
        $this->report['unavailable_vocabularies'] = implode('; ', array_map(static fn (string $scheme, string $reason): string => $scheme.': '.$reason, array_keys($unavailable), $unavailable));

        return (int) $this->report['new_suggestions'];
    }
}
