<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\FundingReference;
use App\Models\Resource;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FundingAwardUriDiscoveryService
{
    /** @var array<string, int|string|bool|null> */
    private array $report = [];

    public function __construct(
        private readonly FundingAwardUriInputProvider $inputs,
        private readonly FunderIdentityResolver $identities,
        private readonly CrossrefGrantSource $crossref,
        private readonly CordisAwardSource $cordis,
        private readonly AwardEvidenceEvaluator $evaluator,
        private readonly FundingAwardUriSuggestionLifecycleService $lifecycle,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function report(): array
    {
        return $this->report;
    }

    /** @param Closure(string): void $progress */
    public function discover(Closure $progress): int
    {
        $this->report = ['checked_resources' => 0, 'checked_references' => 0, 'new_suggestions' => 0,
            'updated_suggestions' => 0, 'stale_suggestions_removed' => 0, 'source_errors' => 0, 'partial' => false];
        if (! config('funding_award_uri.enabled')) {
            $this->report['disabled'] = true;
            $progress('Award URI enrichment is disabled.');

            return 0;
        }
        $this->identities->reset();
        $deadline = microtime(true) + max(0.01, (float) config('funding_award_uri.run_seconds', 300));
        $cursor = (int) (DB::table('funding_award_uri_discovery_states')->where('id', 1)->value('last_resource_id') ?? 0);
        foreach ($this->inputs->resources($cursor) as $resource) {
            if (microtime(true) >= $deadline) {
                $this->report['partial'] = true;
                break;
            }
            if (! $this->processResource($resource, $deadline)) {
                $this->report['partial'] = true;
                break;
            }
            $cursor = $resource->id;
            DB::table('funding_award_uri_discovery_states')->updateOrInsert(['id' => 1], ['last_resource_id' => $cursor, 'updated_at' => now()]);
            $this->increment('checked_resources');
            $progress('Checked '.$this->report['checked_resources'].' resource(s); found '.$this->report['new_suggestions'].' new award URI suggestion(s).');
        }
        if ($this->report['partial'] === false) {
            DB::table('funding_award_uri_discovery_states')->updateOrInsert(['id' => 1], ['last_resource_id' => 0, 'updated_at' => now()]);
        }
        if ((int) $this->report['source_errors'] > 0) {
            $this->report['partial'] = true;
        }
        $progress($this->report['partial'] ? 'Partially checked. Run Check again to continue or retry unavailable sources.' : 'Award URI discovery completed.');

        return (int) $this->report['new_suggestions'];
    }

    private function processResource(Resource $resource, float $deadline): bool
    {
        $this->cleanResource($resource->id);
        if (! AwardMetadata::writable($resource)) {
            return true;
        }
        $inputs = [];
        $counts = [];
        foreach ($resource->fundingReferences as $funding) {
            $number = AwardMetadata::number($funding->award_number);
            $funder = $this->identities->resolve($funding);
            if ($number === null || $funder === null) {
                if (AwardMetadata::text($funding->award_uri) === '') {
                    $this->increment($number === null ? 'skipped_award_number' : 'skipped_funder_unresolved');
                }

                continue;
            }
            $identity = AwardMetadata::identity($funding, $funder);
            $counts[$identity] = ($counts[$identity] ?? 0) + 1;
            if (AwardMetadata::text($funding->award_uri) === '') {
                $inputs[] = [$funding, $number, $funder, $identity];
            }
        }
        foreach ($inputs as [$funding, $number, $funder, $identity]) {
            if (microtime(true) >= $deadline || ! config('funding_award_uri.enabled')) {
                return false;
            }
            $this->increment('checked_references');
            if ($counts[$identity] > 1) {
                $this->increment('skipped_duplicate_local_funding');
                $this->reconcile($funding, $funder, $identity, new AwardMatch(reason: 'duplicate_local_funding'), []);

                continue;
            }
            $results = ['crossref' => $this->crossref->lookup($number, $funder)];
            if ($funder->cordisProgrammes() !== []) {
                $results['cordis'] = $this->cordis->lookup($number, $funder);
            }
            $match = $this->evaluator->evaluate($number, $funding->award_title, $funder, array_values($results));
            $checks = array_map(static fn (AwardSourceResult $result): string => $result->error ?? 'complete', $results);
            $this->reconcile($funding, $funder, $identity, $match, $checks);
        }

        return true;
    }

    private function cleanResource(int $resourceId): void
    {
        DB::transaction(function () use ($resourceId): void {
            $resource = Resource::with(['landingPage', 'fundingReferences'])->lockForUpdate()->find($resourceId);
            if ($resource === null) {
                return;
            }
            $funding = $resource->fundingReferences->keyBy('id');
            foreach (AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)->where('resource_id', $resourceId)->lockForUpdate()->get() as $suggestion) {
                $target = $funding->get($suggestion->target_id);
                if ($target === null || ! AwardMetadata::writable($resource) || AwardMetadata::text($target->award_uri) !== ''
                    || ($suggestion->metadata['source_snapshot_hash'] ?? null) !== AwardMetadata::hash(AwardMetadata::snapshot($target))) {
                    $suggestion->delete();
                    $this->increment('stale_suggestions_removed');
                    $this->lifecycle->changed();
                }
            }
        });
    }

    /** @param array<string, string> $checks */
    private function reconcile(FundingReference $original, FunderIdentity $funder, string $identity, AwardMatch $match, array $checks): void
    {
        DB::transaction(function () use ($original, $funder, $identity, $match, $checks): void {
            $resource = Resource::with('landingPage')->lockForUpdate()->find($original->resource_id);
            $existing = AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)
                ->where('resource_id', $original->resource_id)->where('target_id', $original->id)->lockForUpdate()->get();
            $funding = FundingReference::where('resource_id', $original->resource_id)->lockForUpdate()->find($original->id);
            if ($resource === null || $funding === null || ! config('funding_award_uri.enabled') || ! AwardMetadata::writable($resource)
                || AwardMetadata::text($funding->award_uri) !== '' || AwardMetadata::snapshot($funding) !== AwardMetadata::snapshot($original)) {
                return;
            }
            if (! $this->identities->isUnique($funding, $funder)) {
                $match = new AwardMatch(reason: 'duplicate_local_funding');
            }
            if ($match->candidate === null) {
                $technical = in_array($match->reason, ['source_unavailable', 'invalid_source_response', 'query_incomplete', 'incomplete_grant_record'], true);
                $this->increment($technical ? 'source_errors' : 'skipped_'.$match->reason);
                foreach ($existing as $suggestion) {
                    if ($technical) {
                        $metadata = $suggestion->metadata ?? [];
                        $metadata['source_checks'] = $checks;
                        $metadata['review_fingerprint'] = AwardMetadata::fingerprint($metadata);
                        $suggestion->update(['metadata' => $metadata]);
                    } else {
                        $suggestion->delete();
                        $this->increment('stale_suggestions_removed');
                    }
                    $this->lifecycle->changed();
                }

                return;
            }
            $uri = $match->candidate->uri;
            $dismissed = FundingAwardUriReview::where('resource_id_at_review', $funding->resource_id)
                ->where('funding_identity_hash', $identity)->where('proposed_uri_hash', hash('sha256', $uri))->where('decision', 'declined')->exists();
            foreach ($existing as $suggestion) {
                if ($dismissed || $suggestion->suggested_value !== $uri) {
                    $suggestion->delete();
                    $this->increment('stale_suggestions_removed');
                    $this->lifecycle->changed();
                }
            }
            if ($dismissed) {
                $this->increment('skipped_declined');

                return;
            }
            $evidence = array_map(static fn (AwardCandidate $candidate): array => $candidate->evidence(), $match->evidence);
            $oldest = min([Carbon::parse($match->candidate->fetchedAt)->getTimestamp(), ...array_map(static fn (AwardCandidate $candidate): int => Carbon::parse($candidate->fetchedAt)->getTimestamp(), $match->evidence)]);
            $metadata = [
                'contract_version' => '1', 'rules_version' => (string) config('funding_award_uri.rules_version'),
                'current' => AwardMetadata::snapshot($funding), 'proposed' => ['award_uri' => $uri],
                'funding_identity_hash' => $identity, 'source_snapshot_hash' => AwardMetadata::hash(AwardMetadata::snapshot($funding)),
                'funder' => ['crossref_id' => $funder->crossrefId, 'ids' => $funder->ids, 'provenance' => $funder->provenance],
                'provenance' => $evidence, 'confidence' => ['level' => 'high', 'score' => 1.0,
                    'evidence' => ['exact_award_number', 'verified_funder', 'unique_award', 'verified_award_uri', 'complete_source_checks']],
                'ambiguity' => ['status' => 'none', 'candidate_count' => 1, 'notes' => []], 'source_checks' => $checks,
                'expires_at' => Carbon::createFromTimestamp($oldest)->addDays((int) config('funding_award_uri.evidence_days', 7))->toIso8601String(),
            ];
            $metadata['review_fingerprint'] = AwardMetadata::fingerprint($metadata);
            $suggestion = AssistantSuggestion::updateOrCreate([
                'assistant_id' => AwardMetadata::ASSISTANT_ID, 'target_type' => 'funding_reference',
                'target_id' => $funding->id, 'suggested_value' => $uri,
            ], ['resource_id' => $funding->resource_id, 'suggested_label' => mb_substr($match->candidate->titles[0] ?? $funding->award_number ?? $uri, 0, 1000),
                'similarity_score' => 1.0, 'metadata' => $metadata, 'discovered_at' => now()]);
            $this->increment($suggestion->wasRecentlyCreated ? 'new_suggestions' : 'updated_suggestions');
            $this->lifecycle->changed();
        });
    }

    private function increment(string $key): void
    {
        $this->report[$key] = (int) ($this->report[$key] ?? 0) + 1;
    }
}
