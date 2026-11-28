<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\Resource;
use App\Models\User;
use App\Services\FundingAwardUri\AwardMetadata;
use Illuminate\Support\Facades\DB;
use Modules\Assistants\FundingAwardUriEnrichment\Assistant;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(fn () => F::fake());

it('loads preview dependencies in a bounded number of queries for both list views', function (string $view): void {
    $target = F::target();
    $resourceIds = [$target->resource_id, Resource::factory()->create()->id, Resource::factory()->create()->id];
    $grants = [];
    foreach (range(0, 11) as $index) {
        $number = (string) (218300 + $index);
        $grants[] = F::grant($number, '10.35802/'.$number);
        foreach ($resourceIds as $resourceId) {
            if ($index === 0 && $resourceId === $target->resource_id) {
                continue;
            }
            $target->replicate()->fill(['resource_id' => $resourceId, 'award_number' => $number])->save();
        }
    }
    F::fake($grants);
    F::discover();
    $ids = AssistantSuggestion::orderBy('id')->pluck('id')->all();
    $assistant = app(Assistant::class);
    $measure = function (bool $all) use ($view, $assistant, $resourceIds, $ids): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $items = $view === 'module'
                ? $assistant->loadSuggestions($all ? 100 : 1)->items()
                : $assistant->loadSuggestionsForResources($resourceIds, $all ? null : [$ids[0]]);

            return [$items, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
        }
    };
    [$single, $smallQueries] = $measure(false);
    [$items, $largeQueries] = $measure(true);
    expect($single)->toHaveCount(1)->and($items)->toHaveCount(36)
        ->and(count($largeQueries))->toBeLessThanOrEqual(count($smallQueries) + 1);
    foreach ($items as $item) {
        expect($item['review']['can_accept'])->toBeTrue()->and($item['review']['can_decline'])->toBeTrue();
    }
    $fundingQueries = array_filter($largeQueries, static fn (array $query): bool => str_contains($query['query'], 'funding_references'));
    expect($fundingQueries)->toHaveCount(1);

    // A sibling without a suggestion still makes the local identity ambiguous.
    $target->replicate()->save();
    [$refreshed] = $measure(true);
    $affected = collect($refreshed)->firstWhere('target_id', $target->id);
    expect($affected['metadata']['review_status'])->toBe('duplicate_local_funding')
        ->and($affected['review']['can_accept'])->toBeFalse()->and($affected['review']['can_decline'])->toBeTrue();
    $target->update(['award_uri' => 'https://example.org/editor']);
    [$refreshed] = $measure(true);
    $affected = collect($refreshed)->firstWhere('target_id', $target->id);
    expect($affected['metadata']['live_current']['award_uri'])->toBe('https://example.org/editor')
        ->and($affected['review']['can_decline'])->toBeFalse();
})->with(['module', 'resource']);

it('uses the same decline preconditions in previews and locked writes', function (string $scenario, bool $allowed): void {
    $target = F::target();
    $suggestion = F::discover();
    $metadata = $suggestion->metadata;
    match ($scenario) {
        'removed' => $target->delete(),
        'changed' => $target->update(['award_number' => 'different']),
        'filled' => $target->update(['award_uri' => 'https://example.org/editor']),
        'disabled' => config(['funding_award_uri.enabled' => false]),
        'identity' => $metadata['funding_identity_hash'] = str_repeat('a', 64),
        'expired' => $this->travel(8)->days(),
        'outage' => $metadata['source_checks']['crossref'] = 'source_unavailable',
        'locked' => $target->resource->forceFill(['embargo_registration_started_at' => now()])->save(),
    };
    $metadata['review_fingerprint'] = AwardMetadata::fingerprint($metadata);
    $suggestion->update(['metadata' => $metadata]);
    $assistant = app(Assistant::class);
    $preview = $assistant->getSuggestionForReview($suggestion->id);
    expect($preview['review']['can_accept'])->toBeFalse()->and($preview['review']['can_decline'])->toBe($allowed);
    $result = $assistant->declineSuggestionWithInput($suggestion->id, User::factory()->admin()->create(), null, F::input($suggestion));
    expect($result['success'])->toBe($allowed)->and(FundingAwardUriReview::count())->toBe($allowed ? 1 : 0);
})->with([['removed', false], ['changed', false], ['filled', false], ['disabled', false], ['identity', false],
    ['expired', true], ['outage', true], ['locked', true]]);

it('rejects an entire mixed decline selection before recording any decisions', function (bool $removed): void {
    $target = F::target();
    $second = $target->replicate()->fill(['award_number' => '218301']);
    $second->save();
    F::fake([F::grant(), F::grant('218301', '10.35802/218301')]);
    F::discover();
    F::register();
    $suggestions = AssistantSuggestion::orderBy('id')->get();
    $removed ? $second->delete() : $second->update(['award_title' => 'Changed by editor']);
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/decline', [
        'resource_id' => $target->resource_id,
        'suggestions' => $suggestions->map(fn ($suggestion) => [
            'assistant_id' => AwardMetadata::ASSISTANT_ID, 'suggestion_id' => $suggestion->id, ...F::input($suggestion),
        ])->all(),
    ])->assertUnprocessable();
    expect(FundingAwardUriReview::count())->toBe(0)->and(AssistantSuggestion::count())->toBe(2);
})->with([false, true]);
