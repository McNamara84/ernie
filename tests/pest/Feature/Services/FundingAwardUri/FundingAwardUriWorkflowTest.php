<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\LandingPage;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\FundingAwardUri\AwardMetadata;
use App\Services\FundingAwardUri\FundingAwardUriReviewService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\FundingAwardUriEnrichment\Assistant;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(function (): void {
    F::fake();
});

it('discovers idempotently without changing metadata, then accepts only the URI and retains the review', function (): void {
    $target = F::target();
    $before = AwardMetadata::snapshot($target);
    $suggestion = F::discover();
    expect(F::discover()->id)->toBe($suggestion->id)
        ->and(AwardMetadata::snapshot($target->fresh()))->toBe($before)
        ->and($suggestion->metadata['confidence']['level'])->toBe('high');
    Http::assertSentCount(1);
    $actor = User::factory()->admin()->create();
    $result = app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor);
    expect($result['success'])->toBeTrue()
        ->and(AwardMetadata::snapshot($target->fresh()))->toBe([...$before, 'award_uri' => F::URI])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
    $review = FundingAwardUriReview::sole();
    expect($review->decision)->toBe('accepted')->and($review->reviewed_by_user_id)->toBe($actor->id)
        ->and($review->old_award_uri)->toBeNull()->and($review->new_award_uri)->toBe(F::URI)
        ->and($review->evidence['sources'][0]['provider'])->toBe('crossref')
        ->and($review->reviewer_snapshot['name'])->toBe($actor->name);
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor)['success'])->toBeFalse()
        ->and(FundingAwardUriReview::count())->toBe(1);
    $resource = $target->resource->fresh();
    expect(app(DataCiteJsonExporter::class)->export($resource)['data']['attributes']['fundingReferences'][0]['awardUri'])->toBe(F::URI)
        ->and(app(DataCiteXmlExporter::class)->export($resource))->toContain('awardURI="'.F::URI.'"');
});

it('never searches existing URI fields', function (string $uri): void {
    $target = F::target(['award_uri' => $uri]);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)->and($target->fresh()->award_uri)->toBe($uri);
    Http::assertNothingSent();
})->with(['https://example.org/award', 'invalid existing value']);

it('accepts a whitespace-only URI as empty', function (): void {
    $target = F::target(['award_uri' => '   ']);
    $suggestion = F::discover();
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), User::factory()->admin()->create())['success'])->toBeTrue()
        ->and($target->fresh()->award_uri)->toBe(F::URI)->and(FundingAwardUriReview::sole()->old_award_uri)->toBe('   ');
});

it('rejects stale or altered reviews without recording a decision', function (string $change): void {
    $target = F::target();
    $suggestion = F::discover();
    $input = F::input($suggestion);
    match ($change) {
        'number' => $target->update(['award_number' => 'different']),
        'title' => $target->update(['award_title' => 'different']),
        'name' => $target->update(['funder_name' => 'different']),
        'uri' => $target->update(['award_uri' => 'invalid but present']),
        'removed' => $target->delete(),
        'disabled' => config(['funding_award_uri.enabled' => false]),
        'expired' => $this->travel(8)->days(),
        'fingerprint' => $input = ['award_uri_review_fingerprint' => str_repeat('0', 64)],
        'missing' => $input = [],
        'evidence' => $suggestion->update(['metadata' => [...$suggestion->metadata, 'provenance' => []]]),
        'locked' => $target->resource->forceFill(['embargo_registration_started_at' => now()])->save(),
    };
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, $input, User::factory()->admin()->create())['success'])->toBeFalse()
        ->and(FundingAwardUriReview::count())->toBe(0);
})->with(['number', 'title', 'name', 'uri', 'removed', 'disabled', 'expired', 'fingerprint', 'missing', 'evidence', 'locked']);

it('requires an authorized reviewer even for direct service calls', function (string $role): void {
    F::target();
    $suggestion = F::discover();
    $actor = $role === 'none' ? null : User::factory()->create();
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor)['success'])->toBeFalse()
        ->and(FundingAwardUriReview::count())->toBe(0);
})->with(['none', 'beginner']);

it('archives a decline and suppresses it after funding rows are recreated', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    expect(app(Assistant::class)->declineSuggestionWithInput($suggestion->id, User::factory()->admin()->create(), 'Not our award', F::input($suggestion))['success'])->toBeTrue();
    $replacement = $target->replicate();
    $target->delete();
    $replacement->save();
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)
        ->and($replacement->fresh()->award_uri)->toBeNull()->and(FundingAwardUriReview::sole()->reason)->toBe('Not our award');
});

it('keeps audit snapshots when the reviewer and resource are deleted', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = User::factory()->admin()->create();
    app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor);
    $actor->delete();
    $target->resource->delete();
    $review = FundingAwardUriReview::sole();
    expect($review->resource_id)->toBeNull()->and($review->reviewed_by_user_id)->toBeNull()
        ->and($review->reviewer_snapshot['id'])->toBe($actor->id)->and($review->funding_snapshot['award_number'])->toBe('218300');
    expect(fn () => $review->update(['reason' => 'rewrite']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class);
});

it('does no work when disabled and removes stale suggestions when enabled', function (): void {
    $target = F::target();
    config(['funding_award_uri.enabled' => false]);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    Http::assertNothingSent();
    config(['funding_award_uri.enabled' => true]);
    F::discover();
    $target->update(['award_uri' => 'https://example.org/manual']);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)->and(AssistantSuggestion::count())->toBe(0);
});

it('does not suggest duplicate local funding references', function (): void {
    $target = F::target();
    $target->replicate()->save();
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    Http::assertNothingSent();
});

it('reports provider failure and blocks an earlier proposal until a complete recheck', function (): void {
    F::target();
    $suggestion = F::discover();
    F::fake();
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([], 503)]);
    config(['funding_award_uri.attempts' => 1]);
    $assistant = app(Assistant::class);
    expect($assistant->runDiscovery(fn () => null))->toBe(0)->and($assistant->discoveryDetails()['partial'])->toBeTrue()
        ->and(app(FundingAwardUriReviewService::class)->inspect($suggestion->fresh())['status'])->toBe('source_unavailable');
});

it('rejects internally inconsistent stored previews even with a matching browser fingerprint', function (string $scenario): void {
    $target = F::target();
    $suggestion = F::discover();
    $metadata = $suggestion->metadata;
    match ($scenario) {
        'version' => $metadata['rules_version'] = 'old',
        'confidence' => $metadata['confidence']['level'] = 'low',
        'ambiguity' => $metadata['ambiguity']['status'] = 'ambiguous',
        'expiry' => $metadata['expires_at'] = 'invalid date',
        'identity' => $metadata['funding_identity_hash'] = str_repeat('a', 64),
        'evidence' => $metadata['provenance'] = [],
        'uri' => $metadata['proposed']['award_uri'] = 'https://example.org/mismatch',
    };
    $metadata['review_fingerprint'] = AwardMetadata::fingerprint($metadata);
    $suggestion->update(['metadata' => $metadata]);
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), User::factory()->admin()->create())['success'])->toBeFalse()
        ->and($target->fresh()->award_uri)->toBeNull()->and(FundingAwardUriReview::count())->toBe(0);
})->with(['version', 'confidence', 'ambiguity', 'expiry', 'identity', 'evidence', 'uri']);

it('removes a pending proposal when a complete recheck no longer supports it', function (): void {
    F::target();
    F::discover();
    F::fake([]);
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0)->and(AssistantSuggestion::count())->toBe(0);
});

it('replaces a changed source proposal and refuses the old browser preview', function (): void {
    F::target();
    $old = F::discover();
    F::fake([F::grant(doi: '10.35802/revised')]);
    $new = F::discover();
    expect($new->id)->not->toBe($old->id)->and(AssistantSuggestion::count())->toBe(1)
        ->and(app(Assistant::class)->acceptSuggestion($old->id, F::input($old), User::factory()->admin()->create())['success'])->toBeFalse();
});

it('skips missing award numbers, unresolved funders and tombstones', function (string $scenario): void {
    $target = F::target();
    match ($scenario) {
        'number' => $target->update(['award_number' => null]),
        'funder' => $target->update(['funder_identifier' => null]),
        'tombstone' => LandingPage::factory()->create(['resource_id' => $target->resource_id, 'is_tombstone' => true]),
    };
    expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    Http::assertNothingSent();
})->with(['number', 'funder', 'tombstone']);
