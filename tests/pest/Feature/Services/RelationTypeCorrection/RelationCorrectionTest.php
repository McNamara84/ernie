<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\RelationType;
use App\Models\RelationTypeCorrectionReview;
use App\Models\Resource;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\RelationTypeCorrection\RelationCorrectionCandidateService;
use App\Services\RelationTypeCorrection\RelationEvidence;
use App\Services\RelationTypeCorrection\RelationMetadataClientService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\RelationTypeCorrection\Assistant;
use Tests\Support\RelationCorrectionFixtures as F;

beforeEach(function (): void {
    Cache::flush();
    F::fake();
});

function relationCorrectionSync(DataCiteSyncResult $result, int $times = 1): void
{
    $mock = Mockery::mock(DataCiteSyncService::class);
    $mock->shouldReceive('syncIfRegistered')->times($times)->andReturn($result);
    app()->instance(DataCiteSyncService::class, $mock);
}

it('discovers one high confidence direction correction and refreshes it without duplicates', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    expect($suggestion->target_id)->toBe($target->id)->and($suggestion->metadata['current']['relation_type'])->toBe('HasPart')
        ->and($suggestion->metadata['proposed']['slug'])->toBe('IsPartOf')
        ->and($suggestion->metadata['confidence']['score'])->toBe(0.90);
    $this->travel(2)->hours();
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)
        ->and(AssistantSuggestion::count())->toBe(1);
    Http::assertSentCount(6);
});

it('does not propose already valid uncommon relations, self links, unsupported identifiers or absent metadata', function (): void {
    $target = F::target('IsPartOf');
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    $target->update(['identifier' => F::OWN]);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    $target->update(['identifier' => 'https://example.org']);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    Cache::flush();
    F::resetHttp();
    Http::fake(['*' => Http::response([], 404)]);
    $target->update(['identifier' => F::OTHER]);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
});

it('rejects conflicting primary roles and does not combine weak signals into a correction', function (): void {
    $target = F::target()->load(['resource', 'identifierType', 'relationType']);
    $build = app(RelationCorrectionCandidateService::class);
    $part = new RelationEvidence('datacite', 'datacite:b', F::OTHER, F::OTHER, F::OWN, 'HasPart', 'HasPart', 'https://example.org', '/0', 'now');
    $otherRole = new RelationEvidence('crossref', 'crossref:b', F::OTHER, F::OTHER, F::OWN, 'has-derivation', 'IsSourceOf', 'https://example.org', '/1', 'now');
    $weak = new RelationEvidence('scholexplorer', 'copy:b', F::OTHER, F::OTHER, F::OWN, 'HasPart', 'HasPart', 'https://example.org', '/2', 'now', false);
    expect($build->build($target, [$part, $otherRole]))->toBeNull()
        ->and($build->build($target, [$weak, $weak, $weak]))->toBeNull();
});

it('preserves multiple roles explicitly asserted by the resource itself', function (): void {
    $target = F::target()->load(['resource', 'identifierType', 'relationType']);
    $current = new RelationEvidence('datacite', 'datacite:a', F::OWN, F::OWN, F::OTHER, 'HasPart', 'HasPart', 'https://example.org', '/0', 'now');
    $alternative = new RelationEvidence('datacite', 'datacite:a', F::OWN, F::OWN, F::OTHER, 'IsPartOf', 'IsPartOf', 'https://example.org', '/1', 'now');
    expect(app(RelationCorrectionCandidateService::class)->build($target, [$current, $alternative]))->toBeNull();
});

it('specializes an unqualified Other while preserving compatible schema annotations', function (): void {
    $target = F::target('Other');
    $target->update(['related_metadata_scheme' => 'DDI', 'scheme_uri' => 'https://example.org/ddi', 'scheme_type' => 'XSD']);
    F::fake('IsMetadataFor');
    $suggestion = F::discover();
    expect($suggestion->metadata['proposed']['slug'])->toBe('HasMetadata')
        ->and($suggestion->metadata['current']['related_metadata_scheme'])->toBe('DDI');
    $this->actingAs(F::actor());
    relationCorrectionSync(DataCiteSyncResult::notRequired());
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion))['success'])->toBeTrue()
        ->and($target->fresh()->related_metadata_scheme)->toBe('DDI')->and($target->fresh()->scheme_type)->toBe('XSD');
});

it('reuses DOI source caches across chunk boundaries and loads review titles without per-row queries', function (): void {
    $target = F::target();
    $attributes = $target->getAttributes();
    unset($attributes['id']);
    // This fixture exercises the 100-row boundary with repeated pairs, not a
    // changing external network response or a wall-clock performance assertion.
    DB::table('related_identifiers')->insert(array_fill(0, 100, $attributes));
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(101);
    Http::assertSentCount(6);
    DB::enableQueryLog();
    app(Assistant::class)->loadSuggestionsForResources([$target->resource_id]);
    $titleQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "titles"') || str_contains($query['query'], 'from `titles`'));
    DB::disableQueryLog();
    expect($titleQueries)->toHaveCount(1);
});

it('rechecks the live target after acceptance preflight when a concurrent edit finishes', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    $client = Mockery::mock(RelationMetadataClientService::class);
    $client->shouldReceive('forPair')->once()->andReturnUsing(function () use ($target): array {
        $target->update(['position' => 99]);

        return ['complete' => true, 'sources' => [], 'evidence' => [new RelationEvidence('datacite', 'datacite:b', F::OTHER, F::OTHER, F::OWN, 'HasPart', 'HasPart', 'https://example.org', '/0', 'now')]];
    });
    app()->instance(RelationMetadataClientService::class, $client);
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion))['success'])->toBeFalse()
        ->and(RelationTypeCorrectionReview::count())->toBe(0)->and($suggestion->fresh())->not->toBeNull();
});

it('deduplicates origins, ordering and retrieval times without changing material fingerprints', function (): void {
    $target = F::target()->load(['resource', 'identifierType', 'relationType']);
    $build = app(RelationCorrectionCandidateService::class);
    $a = new RelationEvidence('datacite', 'datacite:b', F::OTHER, F::OTHER, F::OWN, 'HasPart', 'HasPart', 'https://example.org', '/0', 'yesterday');
    $copy = new RelationEvidence('crossref', 'crossref:b', F::OTHER, F::OWN, F::OTHER, 'is-part-of', 'IsPartOf', 'https://example.org', '/9', 'today');
    $first = $build->build($target, [$a]);
    $second = $build->build($target, [$copy, $a, $a]);
    expect($first['context_fingerprint'])->toBe($second['context_fingerprint'])
        ->and($first['review_fingerprint'])->toBe($second['review_fingerprint'])
        ->and($second['confidence']['score'])->toBe(0.90);
    $own = new RelationEvidence('datacite', 'datacite:a', F::OWN, F::OWN, F::OTHER, 'IsPartOf', 'IsPartOf', 'https://example.org', '/0', 'today');
    expect($build->build($target, [$a, $own])['confidence']['score'])->toBe(0.95);
});

it('preserves annotations by suppressing incompatible proposals', function (array $attributes): void {
    $target = F::target();
    $target->update($attributes);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)->and($target->fresh()->getAttributes())->toMatchArray($attributes);
})->with([
    [['relation_type_information' => 'A custom relation meaning']], [['related_metadata_scheme' => 'DDI']],
    [['scheme_uri' => 'https://example.org/ddi']], [['scheme_type' => 'XSD']],
]);

it('keeps existing proposals when primary sources fail and exposes partial scan diagnostics', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    Cache::flush();
    F::resetHttp();
    Http::fake(['*' => Http::response([], 503)]);
    $assistant = app(Assistant::class);
    expect($assistant->runDiscovery(fn () => null))->toBe(0)
        ->and($suggestion->fresh())->not->toBeNull()
        ->and($assistant->discoveryDetails()['incomplete_or_failed_identifiers'])->toBe(1);
});

it('removes obsolete and orphaned proposals and invalidates counts even without new proposals', function (): void {
    $target = F::target();
    F::discover();
    Cache::put(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key(), 1, 120);
    $target->update(['relation_type_id' => RelationType::where('slug', 'IsPartOf')->value('id')]);
    $assistant = app(Assistant::class);
    expect($assistant->runDiscovery(fn () => null))->toBe(0)->and(AssistantSuggestion::count())->toBe(0)
        ->and(Cache::get(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key()))->toBeNull()
        ->and($assistant->discoveryDetails()['stale_suggestions_removed'])->toBe(1);
    $target->update(['relation_type_id' => RelationType::where('slug', 'HasPart')->value('id')]);
    F::discover();
    $target->delete();
    expect($assistant->countPending())->toBe(0)->and($assistant->pendingSuggestionQuery()->count())->toBe(0)
        ->and($assistant->pendingResourceImpactQuery()->count())->toBe(0)
        ->and($assistant->loadSuggestionsForResources([$target->resource_id]))->toBe([])
        ->and($assistant->loadSuggestions(10)->total())->toBe(0);
    $assistant->runDiscovery(fn () => null);
    expect(AssistantSuggestion::count())->toBe(0);
});

it('does not store a stale HTTP result when the editor changes the target during discovery', function (): void {
    $target = F::target();
    $client = Mockery::mock(RelationMetadataClientService::class);
    $client->shouldReceive('forPair')->once()->andReturnUsing(function () use ($target): array {
        $target->update(['citation_label' => 'Edited while the request was running']);

        return ['complete' => true, 'sources' => [], 'evidence' => [new RelationEvidence('datacite', 'datacite:b', F::OTHER, F::OTHER, F::OWN, 'HasPart', 'HasPart', 'https://example.org', '/0', 'now')]];
    });
    app()->instance(RelationMetadataClientService::class, $client);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)->and(AssistantSuggestion::count())->toBe(0);
});

it('accepts only the proposed type, preserves other fields, records the actor and syncs after saving', function (): void {
    $target = F::target();
    $before = $target->getAttributes();
    $suggestion = F::discover();
    $actor = F::actor();
    $this->actingAs($actor);
    $sync = Mockery::mock(DataCiteSyncService::class);
    $sync->shouldReceive('syncIfRegistered')->once()->andReturnUsing(function (Resource $resource) use ($target): DataCiteSyncResult {
        expect($target->fresh()->relationType->slug)->toBe('IsPartOf')->and(RelationTypeCorrectionReview::count())->toBe(1);

        return DataCiteSyncResult::succeeded($resource->doi);
    });
    app()->instance(DataCiteSyncService::class, $sync);
    $result = app(Assistant::class)->acceptSuggestion($suggestion->id, [...F::input($suggestion), 'actor_id' => 999]);
    expect($result['success'])->toBeTrue()->and($result['synced_dois'])->toBe([F::OWN]);
    unset($before['relation_type_id'], $before['updated_at']);
    expect($target->fresh()->getAttributes())->toMatchArray($before)
        ->and($target->resource->fresh()->updated_by_user_id)->toBe($actor->id)
        ->and(AssistantSuggestion::count())->toBe(0);
    $audit = RelationTypeCorrectionReview::firstOrFail();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->decision)->toBe('accepted')
        ->and($audit->snapshot['current']['relation_type'])->toBe('HasPart')
        ->and($audit->snapshot['proposed']['slug'])->toBe('IsPartOf')
        ->and($audit->snapshot['evidence'])->not->toBeEmpty();
});

it('refuses missing or stale previews, unauthorized actors, overrides and inactive types without mutations', function (string $case): void {
    $target = F::target();
    $suggestion = F::discover();
    $input = F::input($suggestion);
    if ($case !== 'unauthenticated') {
        $this->actingAs(F::actor());
    }
    match ($case) {
        'missing' => $input = [],
        'stale-client' => $input['relation_type_correction_fingerprint'] = str_repeat('a', 64),
        'changed-target' => $target->update(['citation_label' => 'New citation label']),
        'changed-resource' => $target->resource->update(['version' => 'new version']),
        'override' => $input['relation_type_id'] = RelationType::where('slug', 'Cites')->value('id'),
        'inactive' => RelationType::where('slug', 'IsPartOf')->update(['is_active' => false]),
        default => null,
    };
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, $input)['success'])->toBeFalse()
        ->and($target->fresh()->relationType->slug)->toBe('HasPart')
        ->and(RelationTypeCorrectionReview::count())->toBe(0)->and(AssistantSuggestion::count())->toBe(1);
})->with(['missing', 'stale-client', 'changed-target', 'changed-resource', 'override', 'inactive', 'unauthenticated']);

it('blocks replacement duplicates even with normalized identifier spellings', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $duplicate = $target->replicate();
    $duplicate->fill(['identifier' => 'HTTPS://DOI.ORG/'.mb_strtoupper(F::OTHER), 'relation_type_id' => RelationType::where('slug', 'IsPartOf')->value('id')])->save();
    $this->actingAs(F::actor());
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion))['success'])->toBeFalse()
        ->and(RelationTypeCorrectionReview::count())->toBe(0);
});

it('leaves independently reviewed local reciprocal records untouched', function (): void {
    $target = F::target();
    $otherResource = Resource::factory()->create(['doi' => F::OTHER]);
    $reverse = $target->replicate()->fill(['resource_id' => $otherResource->id, 'identifier' => F::OWN]);
    $reverse->save();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    relationCorrectionSync(DataCiteSyncResult::notRequired());
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion))['success'])->toBeTrue()
        ->and($reverse->fresh()->relationType->slug)->toBe('HasPart');
});

it('offers and accepts both local directions independently without clearing the other proposal', function (): void {
    $target = F::target();
    $otherResource = Resource::factory()->create(['doi' => F::OTHER]);
    $reverse = $target->replicate()->fill(['resource_id' => $otherResource->id, 'identifier' => F::OWN,
        'relation_type_id' => RelationType::where('slug', 'IsPartOf')->value('id')]);
    $reverse->save();
    F::resetHttp();
    Http::fake(function ($request) {
        foreach ([F::OWN => ['other' => F::OTHER, 'type' => 'IsPartOf'], F::OTHER => ['other' => F::OWN, 'type' => 'HasPart']] as $doi => $role) {
            if (str_contains($request->url(), '/dois/'.rawurlencode($doi))) {
                return Http::response(['data' => ['attributes' => ['doi' => $doi, 'relatedIdentifiers' => [['relatedIdentifierType' => 'DOI', 'relatedIdentifier' => $role['other'], 'relationType' => $role['type']]]]]]);
            }
        }

        return Http::response([], 404);
    });
    F::discover();
    $a = AssistantSuggestion::where('target_id', $target->id)->firstOrFail();
    $b = AssistantSuggestion::where('target_id', $reverse->id)->firstOrFail();
    $this->actingAs(F::actor());
    relationCorrectionSync(DataCiteSyncResult::notRequired(), 2);
    $assistant = app(Assistant::class);
    expect($assistant->acceptSuggestion($a->id, F::input($a))['success'])->toBeTrue()
        ->and($reverse->fresh()->relationType->slug)->toBe('IsPartOf')->and($b->fresh())->not->toBeNull();
    expect($assistant->acceptSuggestion($b->id, F::input($b))['success'])->toBeTrue()
        ->and($reverse->fresh()->relationType->slug)->toBe('HasPart')->and(RelationTypeCorrectionReview::count())->toBe(2);
});

it('rolls back the relation and proposal if the audit insert fails', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    // Force an actual unique constraint failure rather than mocking the transaction.
    RelationTypeCorrectionReview::create(['resource_id' => $target->resource_id, 'related_identifier_id' => $target->id,
        'suggestion_id' => $suggestion->id, 'decision' => 'declined', 'snapshot' => [],
        'context_fingerprint' => str_repeat('a', 64), 'review_fingerprint' => str_repeat('b', 64), 'reviewed_at' => now()]);
    expect(fn () => app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion)))->toThrow(QueryException::class);
    expect($target->fresh()->relationType->slug)->toBe('HasPart')->and($suggestion->fresh())->not->toBeNull();
});

it('declines with audit, suppresses material duplicates and resurfaces genuinely changed context', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = F::actor();
    $assistant = app(Assistant::class);
    expect($assistant->declineSuggestionWithInput($suggestion->id, $actor, 'Reviewed original record', F::input($suggestion))['success'])->toBeTrue()
        ->and(RelationTypeCorrectionReview::first()->decision)->toBe('declined')
        ->and(AssistantDismissed::count())->toBe(1);
    $target->update(['citation_label' => 'A different citation display']);
    $target->update(['identifier' => 'HTTPS://DOI.ORG/'.mb_strtoupper(F::OTHER)]);
    $this->travel(25)->hours();
    expect($assistant->runDiscovery(fn () => null))->toBe(0)->and(AssistantSuggestion::count())->toBe(0);
    $target->resource->update(['version' => '2']);
    expect($assistant->runDiscovery(fn () => null))->toBe(1)->and(AssistantSuggestion::count())->toBe(1);
});

it('refuses stale declines and the legacy decline entry point without context', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $assistant = app(Assistant::class);
    $actor = F::actor();
    expect($assistant->declineSuggestion($suggestion->id, $actor, null)['success'])->toBeFalse();
    $target->update(['identifier' => '10.5880/changed']);
    expect($assistant->declineSuggestionWithInput($suggestion->id, $actor, null, F::input($suggestion))['success'])->toBeFalse()
        ->and(AssistantDismissed::count())->toBe(0)->and(RelationTypeCorrectionReview::count())->toBe(0);
});

it('refreshes expired primary evidence and refuses vanished or changed assertions', function (bool $unavailable): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    $this->travel(25)->hours();
    F::resetHttp();
    Http::fake(['*' => Http::response([], $unavailable ? 503 : 404)]);
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion))['success'])->toBeFalse()
        ->and($target->fresh()->relationType->slug)->toBe('HasPart')
        ->and($suggestion->fresh())->not->toBeNull();
})->with([false, true]);

it('retains accepted changes and audit when DataCite sync fails and returns its retry URL', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    relationCorrectionSync(DataCiteSyncResult::failed(F::OWN, 'Service unavailable'));
    $result = app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion));
    expect($result['success'])->toBeTrue()->and($result['datacite_sync']['success'])->toBeFalse()
        ->and($result['datacite_sync_retry_url'])->toBe(route('assistance.datacite-sync.retry', ['resource' => $target->resource_id]))
        ->and(RelationTypeCorrectionReview::count())->toBe(1)->and($target->fresh()->relationType->slug)->toBe('IsPartOf');
});

it('keeps audit snapshots after deletion of resource, target, type and actor', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = F::actor();
    $this->actingAs($actor);
    relationCorrectionSync(DataCiteSyncResult::notRequired());
    app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion));
    $oldTypeId = $suggestion->metadata['current']['relation_type_id'];
    $target->resource->delete();
    $actor->delete();
    RelationType::whereKey($oldTypeId)->delete();
    $audit = RelationTypeCorrectionReview::firstOrFail();
    expect($audit->resource_id)->toBeNull()->and($audit->related_identifier_id)->toBeNull()->and($audit->actor_id)->toBeNull()
        ->and($audit->snapshot['current']['id'])->toBe($target->id)
        ->and($audit->snapshot['actor']['id'])->toBe($actor->id)
        ->and($audit->snapshot['current']['relation_type'])->toBe('HasPart');
});
