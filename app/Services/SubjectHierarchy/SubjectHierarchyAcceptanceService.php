<?php

declare(strict_types=1);

namespace App\Services\SubjectHierarchy;

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SubjectHierarchyAcceptanceService
{
    public function __construct(private SubjectHierarchyVocabularyService $vocabulary, private DataCiteSyncService $sync) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function accept(AssistantSuggestion $suggestion, array $input): array
    {
        $this->vocabulary->reset();
        $result = DB::transaction(function () use ($suggestion, $input): array {
            $resource = Resource::query()->whereKey($suggestion->resource_id)->lockForUpdate()->first();
            $locked = AssistantSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->first();
            if ($resource === null || $locked === null || $locked->assistant_id !== SubjectHierarchyDiscoveryService::ASSISTANT_ID
                || $locked->target_type !== 'subject_hierarchy' || $locked->target_id !== $resource->id) {
                return $this->failure('The hierarchy suggestion no longer exists or does not match its resource.');
            }
            $metadata = $locked->metadata ?? [];
            $scheme = $metadata['scheme'] ?? null;
            $broaderId = $metadata['broader_id'] ?? null;
            $fingerprint = $input['subject_hierarchy_fingerprint'] ?? null;
            $selected = $input['selected_leaf_ids'] ?? null;
            if (! is_string($scheme) || ! is_string($broaderId) || ! is_string($fingerprint)
                || ! is_array($selected) || ! array_is_list($selected) || $selected === []) {
                return $this->failure('Choose at least one narrower term before accepting.');
            }
            foreach ($selected as $id) {
                if (! is_string($id)) {
                    return $this->failure('The selected narrower terms are invalid.');
                }
            }
            if (count($selected) !== count(array_unique($selected))) {
                return $this->failure('A narrower term cannot be selected twice.');
            }
            $subjects = Subject::where('resource_id', $resource->id)->orderBy('id')->lockForUpdate()->get();
            try {
                $graph = $this->vocabulary->graph($scheme);
                $current = $this->vocabulary->correction($subjects, $graph, $broaderId);
            } catch (Throwable) {
                return $this->failure('The local hierarchy is unavailable. Update the vocabulary and check again.');
            }
            if ($current === null || ($metadata['fingerprint'] ?? null) !== $current['fingerprint']
                || ! hash_equals($current['fingerprint'], $fingerprint)
                || $locked->suggested_value !== hash('sha256', $scheme.'|'.$broaderId).':'.$fingerprint) {
                return $this->failure('This hierarchy suggestion changed. Check the resource again before accepting.');
            }
            if (array_diff($selected, $current['leaf_ids']) !== [] || array_diff($current['existing_leaf_ids'], $selected) !== []) {
                return $this->failure('Select only the offered leaf terms and preserve already assigned narrower terms.');
            }
            foreach ($current['nodes'] as $node) {
                if (! in_array($node['id'], $selected, true) || in_array($node['id'], $current['existing_leaf_ids'], true)) {
                    continue;
                }
                Subject::create([
                    'resource_id' => $resource->id,
                    'value' => $node['label'],
                    'language' => $node['language'],
                    'subject_scheme' => $this->vocabulary->canonicalSubjectScheme($scheme),
                    'scheme_uri' => $node['scheme_uri'],
                    'value_uri' => $node['id'],
                    'classification_code' => $node['classification_code'],
                    'breadcrumb_path' => $node['path'],
                ]);
            }
            $keepBroader = count($selected) === count($current['leaf_ids']);
            if (! $keepBroader) {
                foreach ($subjects as $subject) {
                    if (in_array($subject->id, $current['broader_subject_ids'], true)) {
                        $subject->delete();
                    }
                }
            }
            $locked->delete();
            // Reconcile affected overlapping proposals within the same transaction.
            $this->vocabulary->reset();
            $remainingSubjects = Subject::where('resource_id', $resource->id)->get();
            foreach (AssistantSuggestion::where('assistant_id', SubjectHierarchyDiscoveryService::ASSISTANT_ID)->where('resource_id', $resource->id)->lockForUpdate()->get() as $other) {
                $otherMetadata = $other->metadata ?? [];
                if (($otherMetadata['scheme'] ?? null) !== $scheme || ! is_string($otherMetadata['broader_id'] ?? null) || ($otherMetadata['suggestion_kind'] ?? null) === 'hint') {
                    continue;
                }
                $updated = $this->vocabulary->correction($remainingSubjects, $graph, $otherMetadata['broader_id']);
                if ($updated === null) {
                    $other->delete();

                    continue;
                }
                if (($otherMetadata['fingerprint'] ?? null) === $updated['fingerprint']) {
                    continue;
                }
                $value = hash('sha256', $scheme.'|'.$updated['broader_id']).':'.$updated['fingerprint'];
                $dismissed = AssistantDismissed::where('assistant_id', $other->assistant_id)->where('target_type', $other->target_type)
                    ->where('target_id', $other->target_id)->where('dismissed_value', $value)->exists();
                $alreadyPending = AssistantSuggestion::where('assistant_id', $other->assistant_id)->where('target_type', $other->target_type)
                    ->where('target_id', $other->target_id)->where('suggested_value', $value)->whereKeyNot($other->id)->exists();
                if ($dismissed || $alreadyPending) {
                    $other->delete();
                } else {
                    $other->update(['metadata' => $updated, 'suggested_value' => $value,
                        'suggested_label' => 'Review narrower terms for "'.$updated['broader_label'].'"', 'discovered_at' => now()]);
                }
            }

            return [
                'success' => true,
                'message' => $keepBroader ? 'Narrower terms saved; the broader term was retained.' : 'Narrower terms saved; the broader term was removed.',
                'resource_id' => $resource->id,
            ];
        });
        if ($result['success'] !== true) {
            return $result;
        }
        if (($input['defer_datacite_sync'] ?? false) === true) {
            return [...$result, 'datacite_sync_deferred' => true, 'datacite_sync_resource_ids' => [$suggestion->resource_id]];
        }
        $resource = Resource::find($suggestion->resource_id);
        if ($resource === null) {
            return $result;
        }
        $sync = $this->sync->syncIfRegistered($resource);

        return [
            ...$result,
            'datacite_sync' => $sync->toArray(),
            'datacite_sync_retry_url' => $sync->hasFailed() ? route('assistance.datacite-sync.retry', ['resource' => $resource->id]) : null,
            'synced_dois' => $sync->attempted && $sync->success && $sync->doi !== null ? [$sync->doi] : [],
        ];
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
