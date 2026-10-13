<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Enums\CacheKey;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\RelationTypeCorrectionReview;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\DB;

final readonly class RelationCorrectionReviewService
{
    public function __construct(private RelationMetadataClientService $client, private RelationCorrectionCandidateService $candidate, private DataCiteSyncService $sync) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function review(AssistantSuggestion $suggestion, User $actor, string $decision, array $input, ?string $reason = null): array
    {
        $fingerprint = $input['relation_type_correction_fingerprint'] ?? null;
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return $this->failure('Review the relation type preview before continuing.');
        }
        $target = RelatedIdentifier::with(['resource', 'identifierType', 'relationType'])->find($suggestion->target_id);
        if ($target === null || $target->resource_id !== $suggestion->resource_id || $suggestion->target_type !== 'related_identifier') {
            return $this->failure('The related identifier no longer belongs to this resource.');
        }
        $own = RelationTypeRules::doi($target->resource->doi ?? '');
        $other = RelationTypeRules::doi($target->identifier);
        if ($own === null || $other === null || $own === $other) {
            return $this->failure('The identifier context changed. Run the check again.');
        }
        // Refresh expired source caches before obtaining database locks.
        $source = $this->client->forPair($own, $other);
        if (! $source['complete']) {
            return $this->failure('Primary metadata is temporarily unavailable. Try again after running a new check.');
        }
        $preflight = $this->candidate->build($target, $source['evidence'], $source['sources']);
        if ($preflight === null || $preflight['review_fingerprint'] !== $fingerprint) {
            return $this->failure('This proposal changed. Run the check and review the new preview.');
        }
        $result = DB::transaction(function () use ($suggestion, $actor, $decision, $input, $reason, $fingerprint, $source): array {
            $resource = Resource::whereKey($suggestion->resource_id)->lockForUpdate()->first();
            // Lock related rows in a deterministic order; this also guards replacements
            // against an identical relation introduced by another editor.
            $rows = RelatedIdentifier::with(['identifierType', 'relationType'])->where('resource_id', $suggestion->resource_id)
                ->orderBy('id')->lockForUpdate()->get();
            $live = $rows->firstWhere('id', $suggestion->target_id);
            $locked = AssistantSuggestion::where('assistant_id', RelationCorrectionDiscoveryService::ID)->whereKey($suggestion->id)->lockForUpdate()->first();
            if ($resource === null || $live === null || $locked === null || $locked->resource_id !== $resource->id
                || $locked->target_id !== $live->id || $locked->target_type !== 'related_identifier') {
                return $this->failure('The proposal no longer exists or has changed its target.');
            }
            $live->setRelation('resource', $resource);
            $current = $this->candidate->build($live, $source['evidence'], $source['sources']);
            $stored = $locked->metadata ?? [];
            if ($current === null || ($stored['review_fingerprint'] ?? null) !== $fingerprint
                || $current['review_fingerprint'] !== $fingerprint
                || $locked->suggested_value !== $current['proposed']['slug'].':'.$current['context_fingerprint']) {
                return $this->failure('This proposal changed. Run the check and review the new preview.');
            }
            $type = RelationType::whereKey($current['proposed']['id'])->where('is_active', true)->lockForUpdate()->first();
            if ($type === null || $type->slug !== $current['proposed']['slug'] || $type->name !== $current['proposed']['name']
                || (isset($input['relation_type_id']) && (int) $input['relation_type_id'] !== $type->id)) {
                return $this->failure('Only the displayed active relation type can be accepted.');
            }
            if ($decision === 'accepted') {
                foreach ($rows as $row) {
                    if ($row->id !== $live->id && $row->relation_type_id === $type->id
                        && $row->identifier_type_id === $live->identifier_type_id
                        && RelationTypeRules::doi($row->identifier) === RelationTypeRules::doi($live->identifier)) {
                        return $this->failure('An equivalent relation already exists. Review the resource manually.');
                    }
                }
            }
            RelationTypeCorrectionReview::create([
                'resource_id' => $resource->id, 'related_identifier_id' => $live->id, 'actor_id' => $actor->id,
                'suggestion_id' => $locked->id, 'decision' => $decision, 'reason' => $reason,
                'context_fingerprint' => $current['context_fingerprint'], 'review_fingerprint' => $fingerprint,
                'snapshot' => [...$current, 'actor' => ['id' => $actor->id, 'name' => $actor->name], 'suggestion_id' => $locked->id],
                'reviewed_at' => now(),
            ]);
            if ($decision === 'accepted') {
                $live->update(['relation_type_id' => $type->id]);
                $resource->forceFill(['updated_by_user_id' => $actor->id])->touch();
                AssistantSuggestion::where('assistant_id', RelationCorrectionDiscoveryService::ID)->where('target_type', 'related_identifier')->where('target_id', $live->id)->delete();
            } else {
                AssistantDismissed::firstOrCreate([
                    'assistant_id' => RelationCorrectionDiscoveryService::ID, 'target_type' => 'related_identifier',
                    'target_id' => $live->id, 'dismissed_value' => $locked->suggested_value,
                ], ['dismissed_by' => $actor->id, 'reason' => $reason]);
                $locked->delete();
            }

            return ['success' => true, 'message' => $decision === 'accepted' ? 'Relation type corrected.' : 'Relation type proposal declined.', 'resource_id' => $resource->id];
        });
        if ($result['success'] !== true) {
            return $result;
        }
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();
        CacheKey::ASSISTANCE_DATACENTER_OPTIONS->forget();
        if ($decision !== 'accepted') {
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

        return [...$result, 'datacite_sync' => $sync->toArray(),
            'datacite_sync_retry_url' => $sync->hasFailed() ? route('assistance.datacite-sync.retry', ['resource' => $resource->id]) : null,
            'synced_dois' => $sync->attempted && $sync->success && $sync->doi !== null ? [$sync->doi] : []];
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
