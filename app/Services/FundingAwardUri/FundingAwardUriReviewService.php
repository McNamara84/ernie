<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Enums\PortalCacheArea;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Models\User;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteSyncService;
use App\Services\OaiPmh\OaiPmhHarvestService;
use App\Services\PortalCacheInvalidationService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final readonly class FundingAwardUriReviewService
{
    public function __construct(
        private FunderIdentityResolver $identities,
        private AwardEvidenceEvaluator $evaluator,
        private FundingAwardUriSuggestionLifecycleService $lifecycle,
        private DataCiteSyncService $sync,
    ) {}

    /** @return array{status: string|null, decline_status: string|null, current: array<string, mixed>|null} */
    public function inspect(AssistantSuggestion $suggestion): array
    {
        return $this->inspectMany([$suggestion])[$suggestion->id];
    }

    /**
     * All context is local to this presentation call; writes always reload under locks.
     *
     * @param  array<int, AssistantSuggestion>  $suggestions
     * @return array<int, array{status: string|null, decline_status: string|null, current: array<string, mixed>|null}>
     */
    public function inspectMany(array $suggestions): array
    {
        if ($suggestions === []) {
            return [];
        }
        $this->identities->reset();
        $resources = Resource::with(['landingPage', 'titles.titleType', 'fundingReferences.funderIdentifierType'])
            ->whereKey(array_unique(array_map(static fn (AssistantSuggestion $suggestion): int => $suggestion->resource_id, $suggestions)))
            ->get()->keyBy('id');
        $fundings = [];
        $funders = [];
        $identities = [];
        $counts = [];
        foreach ($resources as $resource) {
            foreach ($resource->fundingReferences as $funding) {
                $funding->setRelation('resource', $resource);
                $fundings[$resource->id][$funding->id] = $funding;
                $funder = $funders[$funding->id] = $this->identities->resolve($funding);
                if ($funder !== null) {
                    $identity = $identities[$funding->id] = AwardMetadata::identity($funding, $funder);
                    $counts[$resource->id][$identity] = ($counts[$resource->id][$identity] ?? 0) + 1;
                }
            }
        }
        $inspections = [];
        foreach ($suggestions as $suggestion) {
            $suggestion->setRelation('resource', $resources->get($suggestion->resource_id));
            $funding = $fundings[$suggestion->resource_id][$suggestion->target_id] ?? null;
            $funder = $funders[$suggestion->target_id] ?? null;
            $identity = $identities[$suggestion->target_id] ?? '';
            $unique = ($counts[$suggestion->resource_id][$identity] ?? 0) === 1;
            $inspections[$suggestion->id] = [
                'status' => $this->validation($suggestion, $funding, $funder, $unique),
                'decline_status' => $this->declineValidation($suggestion, $funding, $funder),
                'current' => $funding === null ? null : AwardMetadata::snapshot($funding),
            ];
        }

        return $inspections;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function review(AssistantSuggestion $original, ?User $reviewer, array $input, string $decision, ?string $reason = null): array
    {
        if ($reviewer === null || ! Gate::forUser($reviewer)->allows('access-assistance')) {
            return $this->failure('An authorized reviewer is required.');
        }
        if (! in_array($decision, ['accepted', 'declined'], true) || ($reason !== null && mb_strlen($reason) > 255)) {
            return $this->failure('Invalid review decision.');
        }
        $this->identities->reset();
        $result = DB::transaction(function () use ($original, $reviewer, $input, $decision, $reason): array {
            $resource = Resource::with('landingPage')->lockForUpdate()->find($original->resource_id);
            $suggestion = AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)->lockForUpdate()->find($original->id);
            if ($resource === null || $suggestion === null || $suggestion->resource_id !== $resource->id || $suggestion->target_type !== 'funding_reference') {
                return $this->failure('The suggestion no longer exists or was already processed.');
            }
            $funding = FundingReference::with('funderIdentifierType')->where('resource_id', $resource->id)->lockForUpdate()->find($suggestion->target_id);
            $metadata = $suggestion->metadata ?? [];
            $fingerprint = $input['award_uri_review_fingerprint'] ?? null;
            if (! is_string($fingerprint) || ! hash_equals(AwardMetadata::fingerprint($metadata), $fingerprint)
                || ($metadata['review_fingerprint'] ?? null) !== $fingerprint) {
                return $this->failure('The award URI preview changed. Reload and review it again.');
            }
            $funding?->setRelation('resource', $resource);
            $funder = $funding === null ? null : $this->identities->resolve($funding);
            $status = $this->declineValidation($suggestion, $funding, $funder);
            if ($status !== null || $funding === null || $funder === null) {
                return $this->failure('The award URI cannot be reviewed: '.str_replace('_', ' ', $status ?? 'invalid_evidence').'. Run Check again.');
            }
            if ($decision === 'accepted' && ($status = $this->validation($suggestion, $funding, $funder)) !== null) {
                return $this->failure('The award URI cannot be accepted: '.str_replace('_', ' ', $status).'. Run Check again.');
            }
            $identity = $metadata['funding_identity_hash'] ?? null;
            $old = $funding->award_uri;
            $new = $decision === 'accepted' ? $suggestion->suggested_value : $old;
            $review = FundingAwardUriReview::create([
                'decision' => $decision, 'suggestion_id_at_review' => $suggestion->id,
                'resource_id' => $resource->id, 'resource_id_at_review' => $resource->id,
                'funding_reference_id_at_review' => $funding->id, 'reviewed_by_user_id' => $reviewer->id,
                'reviewer_snapshot' => ['id' => $reviewer->id, 'name' => $reviewer->name],
                'resource_snapshot' => ['id' => $resource->id, 'doi' => $resource->doi, 'title' => $resource->main_title],
                'funding_snapshot' => AwardMetadata::snapshot($funding), 'funding_identity_hash' => $identity,
                'review_fingerprint' => $fingerprint, 'proposed_uri_hash' => hash('sha256', $suggestion->suggested_value),
                'old_award_uri' => $old, 'new_award_uri' => $new, 'proposed_award_uri' => $suggestion->suggested_value,
                'evidence' => ['sources' => array_map(static fn (AwardCandidate $candidate): array => $candidate->evidence(),
                    $this->evaluator->restore($metadata['provenance'] ?? null)->candidates), 'funder' => $funder->provenance],
                'confidence' => $metadata['confidence'] ?? [], 'ambiguity' => $metadata['ambiguity'] ?? [],
                'rules_version' => (string) ($metadata['rules_version'] ?? ''), 'reason' => $reason, 'reviewed_at' => now(),
            ]);
            if ($decision === 'accepted') {
                $funding->update(['award_uri' => $new]);
                $resource->forceFill(['updated_by_user_id' => $reviewer->id])->touch();
                app(PortalCacheInvalidationService::class)->scheduleForResourceId($resource->id, [PortalCacheArea::PAGE]);
                // Even a same-second touch must invalidate cached funding data after commit.
                DB::afterCommit(static function () use ($resource): void {
                    app(ResourceCacheService::class)->invalidateResourceCache($resource->id);
                    $landingCache = app(LandingPageRenderDataCacheService::class);
                    if ($resource->igsnMetadata()->exists()) {
                        $landingCache->forgetForIgsnFamilies([$resource->id]);
                    } elseif ($resource->landingPage !== null) {
                        $landingCache->forget($resource->landingPage);
                    }
                    app(OaiPmhHarvestService::class)->invalidateResource($resource->id);
                });
            } else {
                AssistantDismissed::firstOrCreate(['assistant_id' => AwardMetadata::ASSISTANT_ID, 'target_type' => 'funding_reference',
                    'target_id' => $funding->id, 'dismissed_value' => $suggestion->suggested_value], ['dismissed_by' => $reviewer->id, 'reason' => $reason]);
            }
            AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)->where('resource_id', $resource->id)
                ->where('target_type', 'funding_reference')->where('target_id', $funding->id)->delete();
            $this->lifecycle->changed();

            return ['success' => true, 'message' => $decision === 'accepted' ? 'Award URI saved. Your review and source evidence were retained.' : 'Award URI suggestion declined.',
                'resource_id' => $resource->id, 'review_id' => $review->id];
        });
        if ($result['success'] !== true || $decision !== 'accepted') {
            return $result;
        }
        if (($input['defer_datacite_sync'] ?? false) === true) {
            return [...$result, 'datacite_sync_deferred' => true, 'datacite_sync_resource_ids' => [$result['resource_id']]];
        }
        $resource = Resource::find($result['resource_id']);
        if ($resource === null) {
            return $result;
        }
        $sync = $this->sync->syncIfRegistered($resource);

        return [...$result, 'datacite_sync' => $sync->toArray(),
            'message' => $result['message'].($sync->hasFailed() ? ' DataCite synchronization failed; the local change is saved.' : ''),
            'datacite_sync_retry_url' => $sync->hasFailed() ? route('assistance.datacite-sync.retry', ['resource' => $resource->id]) : null,
            'synced_dois' => $sync->attempted && $sync->success && $sync->doi !== null ? [$sync->doi] : []];
    }

    /** Preconditions shared by decline previews and the locked review transaction. */
    private function declineValidation(AssistantSuggestion $suggestion, ?FundingReference $funding, ?FunderIdentity $funder): ?string
    {
        if (! config('funding_award_uri.enabled')) {
            return 'disabled';
        }
        if ($suggestion->assistant_id !== AwardMetadata::ASSISTANT_ID || $suggestion->target_type !== 'funding_reference'
            || $funding === null || $funding->resource_id !== $suggestion->resource_id) {
            return 'target_missing';
        }
        $metadata = $suggestion->metadata ?? [];
        if (($metadata['review_fingerprint'] ?? null) !== AwardMetadata::fingerprint($metadata)) {
            return 'invalid_evidence';
        }
        if (($metadata['source_snapshot_hash'] ?? null) !== AwardMetadata::hash(AwardMetadata::snapshot($funding))) {
            return 'local_data_changed';
        }
        if ($funder === null || ($metadata['funding_identity_hash'] ?? null) !== AwardMetadata::identity($funding, $funder)) {
            return 'funder_unresolved';
        }
        if (AwardMetadata::uri($suggestion->suggested_value) !== $suggestion->suggested_value) {
            return 'invalid_award_uri';
        }

        return null;
    }

    private function validation(AssistantSuggestion $suggestion, ?FundingReference $funding, ?FunderIdentity $funder, ?bool $unique = null): ?string
    {
        $status = $this->declineValidation($suggestion, $funding, $funder);
        if ($status !== null || $funding === null || $funder === null) {
            return $status ?? 'invalid_evidence';
        }
        if (! AwardMetadata::writable($funding->resource)) {
            return 'resource_locked';
        }
        $metadata = $suggestion->metadata ?? [];
        if (AwardMetadata::text($funding->award_uri) !== '' || ! is_array($metadata['current'] ?? null)
            || AwardMetadata::hash($metadata['current']) !== AwardMetadata::hash(AwardMetadata::snapshot($funding))
            || ($metadata['source_snapshot_hash'] ?? null) !== AwardMetadata::hash(AwardMetadata::snapshot($funding))) {
            return 'local_data_changed';
        }
        if (($metadata['contract_version'] ?? null) !== '1' || ($metadata['rules_version'] ?? null) !== (string) config('funding_award_uri.rules_version')
            || ($metadata['review_fingerprint'] ?? null) !== AwardMetadata::fingerprint($metadata)
            || ($metadata['confidence']['level'] ?? null) !== 'high' || ($metadata['ambiguity']['status'] ?? null) !== 'none'
            || ($metadata['proposed']['award_uri'] ?? null) !== $suggestion->suggested_value) {
            return 'invalid_evidence';
        }
        try {
            if (! is_string($metadata['expires_at'] ?? null) || Carbon::parse($metadata['expires_at'])->lte(now())) {
                return 'evidence_expired';
            }
        } catch (Throwable) {
            return 'invalid_evidence';
        }
        $number = AwardMetadata::number($funding->award_number);
        if ($number === null) {
            return 'funder_unresolved';
        }
        if (! ($unique ?? $this->identities->isUnique($funding, $funder))) {
            return 'duplicate_local_funding';
        }
        $checks = $metadata['source_checks'] ?? [];
        if (! is_array($checks) || ($checks['crossref'] ?? null) !== 'complete'
            || ($funder->cordisProgrammes() !== [] && ($checks['cordis'] ?? null) !== 'complete')
            || ! array_all($checks, static fn (mixed $status): bool => $status === 'complete')) {
            return 'source_unavailable';
        }
        $match = $this->evaluator->evaluate($number, $funding->award_title, $funder, [$this->evaluator->restore($metadata['provenance'] ?? null)]);

        return $match->candidate?->uri === $suggestion->suggested_value ? null : $match->reason;
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
