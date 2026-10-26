<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\User;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(function (): void {
    F::fake();
});

it('presents a compatible complete preview and exposes the module', function (): void {
    F::target();
    F::discover();
    F::register();
    $this->actingAs(User::factory()->admin()->create())->get('/assistance')->assertOk()->assertInertia(fn ($page) => $page
        ->where('manifests', fn ($manifests) => collect($manifests)->contains('id', 'funding-award-uri-enrichment')));
    $this->getJson('/assistance/data/funding-award-uri-enrichment')->assertOk()
        ->assertJsonPath('data.0.suggestions.0.review.can_accept', true)
        ->assertJsonPath('data.0.suggestions.0.review.exclusive_target_key', null)
        ->assertJsonPath('data.0.suggestions.0.metadata.proposed.award_uri', F::URI);
});

it('accepts or declines the preview and uses the authenticated reviewer', function (string $action): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = User::factory()->groupLeader()->create();
    F::register();
    $this->actingAs($actor)->postJson('/assistance/funding-award-uris/'.$suggestion->id.'/'.$action,
        [...F::input($suggestion), 'reviewer_id' => 999, 'reason' => 'Checked'])
        ->assertOk()->assertJsonPath('success', true);
    expect(FundingAwardUriReview::sole()->reviewed_by_user_id)->toBe($actor->id)
        ->and($target->fresh()->award_uri)->toBe($action === 'accept' ? F::URI : null);
})->with(['accept', 'decline']);

it('denies unauthenticated and unauthorized single and batch actions', function (string $action, bool $batch): void {
    $target = F::target();
    $suggestion = F::discover();
    F::register();
    $url = $batch ? '/assistance/suggestions/batch/'.$action : '/assistance/funding-award-uris/'.$suggestion->id.'/'.$action;
    $payload = $batch ? ['resource_id' => $target->resource_id, 'suggestions' => [[
        'assistant_id' => 'funding-award-uri-enrichment', 'suggestion_id' => $suggestion->id, ...F::input($suggestion),
    ]]] : F::input($suggestion);
    $this->postJson($url, $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->curator()->create())->postJson($url, $payload)->assertForbidden();
    expect(FundingAwardUriReview::count())->toBe(0);
})->with([['accept', false], ['decline', false], ['accept', true], ['decline', true]]);

it('processes two selected awards and syncs once per resource', function (string $action): void {
    $target = F::target();
    F::target(['resource_id' => $target->resource_id, 'award_number' => '218301']);
    F::fake([F::grant(), F::grant('218301', '10.35802/218301')]);
    F::discover();
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->times($action === 'accept' ? 1 : 0)
        ->andReturn(DataCiteSyncResult::notRequired());
    F::register();
    $suggestions = AssistantSuggestion::all();
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/'.$action, [
        'resource_id' => $target->resource_id, 'suggestions' => $suggestions->map(fn ($suggestion) => [
            'assistant_id' => 'funding-award-uri-enrichment', 'suggestion_id' => $suggestion->id, ...F::input($suggestion),
        ])->all(),
    ])->assertOk()->assertJsonPath('success_count', 2)->assertJsonPath('failure_count', 0);
    expect(FundingAwardUriReview::count())->toBe(2);
})->with(['accept', 'decline']);

it('validates all batch fingerprints before changing any funding reference', function (string $action): void {
    $target = F::target();
    F::target(['resource_id' => $target->resource_id, 'award_number' => '218301']);
    F::fake([F::grant(), F::grant('218301', '10.35802/218301')]);
    F::discover();
    F::register();
    $suggestions = AssistantSuggestion::orderBy('id')->get();
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/'.$action, [
        'resource_id' => $target->resource_id, 'suggestions' => [
            ['assistant_id' => 'funding-award-uri-enrichment', 'suggestion_id' => $suggestions[0]->id, ...F::input($suggestions[0])],
            ['assistant_id' => 'funding-award-uri-enrichment', 'suggestion_id' => $suggestions[1]->id],
        ],
    ])->assertUnprocessable();
    expect(FundingAwardUriReview::count())->toBe(0)->and(AssistantSuggestion::count())->toBe(2);
})->with(['accept', 'decline']);
