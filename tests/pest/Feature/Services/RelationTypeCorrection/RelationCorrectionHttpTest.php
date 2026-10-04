<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\RelationTypeCorrectionReview;
use App\Models\User;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RelationCorrectionFixtures as F;

beforeEach(function (): void {
    Cache::flush();
    F::fake();
});

it('registers the correction module and exposes its preview to authorized curators', function (): void {
    F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor())->getJson('/assistance/data/summary')->assertOk();
    $this->get('/assistance')->assertOk()->assertInertia(fn ($page) => $page->component('assistance')
        ->where('manifests', fn ($manifests): bool => collect($manifests)->contains('id', 'relation-type-correction')));
});

it('accepts and declines through the generic single-action routes', function (string $action): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = F::actor();
    if ($action === 'accept') {
        $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    }
    F::registerAssistant();
    $this->actingAs($actor)->postJson('/assistance/relation-type-correction/'.$suggestion->id.'/'.$action,
        [...F::input($suggestion), 'reason' => 'Reviewed', 'actor_id' => 999])->assertOk()->assertJsonPath('success', true);
    expect(RelationTypeCorrectionReview::firstOrFail()->actor_id)->toBe($actor->id)
        ->and($target->fresh()->relationType->slug)->toBe($action === 'accept' ? 'IsPartOf' : 'HasPart');
})->with(['accept', 'decline']);

it('requires a valid fingerprint and rejects unauthorized single or batch actions', function (string $action, bool $batch): void {
    $target = F::target();
    $suggestion = F::discover();
    $url = $batch ? '/assistance/suggestions/batch/'.$action : '/assistance/relation-type-correction/'.$suggestion->id.'/'.$action;
    $payload = $batch ? ['resource_id' => $target->resource_id, 'suggestions' => [['assistant_id' => 'relation-type-correction', 'suggestion_id' => $suggestion->id, ...F::input($suggestion)]]] : F::input($suggestion);
    $this->postJson($url, $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->curator()->create())->postJson($url, $payload)->assertForbidden();
    $this->actingAs(F::actor());
    if ($batch) {
        $payload['suggestions'][0]['relation_type_correction_fingerprint'] = 'bad';
    } else {
        $payload['relation_type_correction_fingerprint'] = 'bad';
    }
    $this->postJson($url, $payload)->assertUnprocessable();
    expect(RelationTypeCorrectionReview::count())->toBe(0)->and(AssistantSuggestion::count())->toBe(1);
})->with([['accept', false], ['decline', false], ['accept', true], ['decline', true]]);

it('passes the displayed fingerprint for both resource batch actions and syncs acceptance once per resource', function (string $action): void {
    $target = F::target();
    $second = $target->replicate()->fill(['identifier' => '10.5880/correction.c']);
    $second->save();
    F::resetHttp();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/dois/') && (str_contains($request->url(), rawurlencode(F::OTHER)) || str_contains($request->url(), rawurlencode('10.5880/correction.c')))) {
            $doi = str_contains($request->url(), rawurlencode(F::OTHER)) ? F::OTHER : '10.5880/correction.c';

            return Http::response(['data' => ['attributes' => ['doi' => $doi, 'relatedIdentifiers' => [['relatedIdentifierType' => 'DOI', 'relatedIdentifier' => F::OWN, 'relationType' => 'HasPart']]]]]);
        }

        return Http::response([], 404);
    });
    F::discover();
    $suggestions = AssistantSuggestion::where('assistant_id', 'relation-type-correction')->get();
    if ($action === 'accept') {
        $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    }
    $this->actingAs(F::actor())->postJson('/assistance/suggestions/batch/'.$action, [
        'resource_id' => $target->resource_id, 'suggestions' => $suggestions->map(fn ($suggestion): array => [
            'assistant_id' => 'relation-type-correction', 'suggestion_id' => $suggestion->id, ...F::input($suggestion),
        ])->values()->all(),
    ])->assertOk()->assertJsonPath('failure_count', 0)->assertJsonPath('success_count', $suggestions->count());
    expect(RelationTypeCorrectionReview::count())->toBe($suggestions->count());
})->with(['accept', 'decline']);

it('rejects a stale batch item without changing its relation or audit', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $target->update(['citation_label' => 'Changed after preview']);
    $this->actingAs(F::actor())->postJson('/assistance/suggestions/batch/accept', [
        'resource_id' => $target->resource_id, 'suggestions' => [[
            'assistant_id' => 'relation-type-correction', 'suggestion_id' => $suggestion->id, ...F::input($suggestion),
        ]],
    ])->assertOk()->assertJsonPath('failure_count', 1);
    expect(RelationTypeCorrectionReview::count())->toBe(0)->and($target->fresh()->relationType->slug)->toBe('HasPart');
});

it('rejects incomplete correction batch previews before any selected proposal is consumed', function (string $action): void {
    $target = F::target();
    $second = $target->replicate();
    $second->save();
    F::discover();
    $suggestions = AssistantSuggestion::where('assistant_id', 'relation-type-correction')->orderBy('id')->get();
    $this->actingAs(F::actor())->postJson('/assistance/suggestions/batch/'.$action, [
        'resource_id' => $target->resource_id, 'suggestions' => [
            ['assistant_id' => 'relation-type-correction', 'suggestion_id' => $suggestions[0]->id, ...F::input($suggestions[0])],
            ['assistant_id' => 'relation-type-correction', 'suggestion_id' => $suggestions[1]->id],
        ],
    ])->assertUnprocessable();
    expect(AssistantSuggestion::count())->toBe(2)->and(RelationTypeCorrectionReview::count())->toBe(0);
})->with(['accept', 'decline']);
