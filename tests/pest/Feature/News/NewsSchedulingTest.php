<?php

declare(strict_types=1);

use App\Enums\NewsKind;
use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use App\Services\News\HomepageNewsService;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Support/NewsFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-15T10:00:00Z'));
    $this->actingAs(User::factory()->admin()->create());
});

it('uses exact half-open time boundaries without background writes', function (string $time, bool $override): void {
    $temporary = News::factory()->scheduled()->create(['starts_at' => '2030-01-15 11:00:00', 'ends_at' => '2030-01-15 12:00:00']);
    $this->travelTo(CarbonImmutable::parse($time));
    $regular = HomepageNewsState::findOrFail(1)->active_news_id;
    expect(app(HomepageNewsService::class)->current()->id)->toBe($override ? $temporary->id : $regular)
        ->and($temporary->fresh()->revision)->toBe(1)->and($temporary->fresh()->is_scheduled)->toBeTrue();
})->with([
    ['2030-01-15T10:59:59Z', false], ['2030-01-15T11:00:00Z', true],
    ['2030-01-15T11:59:59Z', true], ['2030-01-15T12:00:00Z', false], ['2030-01-15T12:00:01Z', false],
]);

it('rejects all overlap shapes but allows adjacent intervals', function (string $start, string $end, bool $allowed): void {
    News::factory()->scheduled()->create(['title' => 'Existing maintenance', 'starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 12:00:00']);
    $news = News::factory()->temporary()->create(['starts_at' => '2030-01-20 '.$start, 'ends_at' => '2030-01-20 '.$end]);
    $response = $this->postJson("/manage-news/{$news->id}/schedule", newsActionData($news));
    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        expect($response->json('errors.starts_at.0'))->toContain('Existing maintenance', 'Europe/Berlin');
    }
    expect($news->fresh()->is_scheduled)->toBe($allowed);
})->with([
    'same' => ['10:00:00', '12:00:00', false], 'inside' => ['10:30:00', '11:00:00', false],
    'contains' => ['09:00:00', '13:00:00', false], 'left' => ['09:00:00', '11:00:00', false],
    'right' => ['11:00:00', '13:00:00', false], 'before' => ['09:00:00', '10:00:00', true],
    'after' => ['12:00:00', '13:00:00', true],
]);

it('ignores drafts and archived windows and rechecks edits to a planned period', function (): void {
    News::factory()->temporary()->create(['starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 14:00:00']);
    News::factory()->temporary()->archived()->create(['starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 14:00:00']);
    $first = News::factory()->temporary()->create(['starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 12:00:00']);
    $second = News::factory()->scheduled()->create(['starts_at' => '2030-01-20 12:00:00', 'ends_at' => '2030-01-20 14:00:00']);
    $this->postJson("/manage-news/{$first->id}/schedule", newsActionData($first))->assertOk();
    $data = newsInput(['revision' => $first->fresh()->revision, 'kind' => 'temporary', 'starts_at' => '2030-01-20T11:00', 'ends_at' => '2030-01-20T13:00']);
    $this->patchJson("/manage-news/{$first->id}", $data)->assertOk();
    $data['revision'] = $first->fresh()->revision;
    $data['ends_at'] = '2030-01-20T13:30';
    $this->patchJson("/manage-news/{$first->id}", $data)->assertUnprocessable();
    expect($first->fresh()->ends_at->format('H:i'))->toBe('12:00')->and($second->fresh()->is_scheduled)->toBeTrue();
    $data['kind'] = 'regular';
    $data['starts_at'] = $data['ends_at'] = null;
    $this->patchJson("/manage-news/{$first->id}", $data)->assertUnprocessable();
});

it('returns to the newly selected regular news and preserves expired announcements', function (): void {
    $temporary = News::factory()->scheduled()->create(['starts_at' => '2030-01-15 09:00:00', 'ends_at' => '2030-01-15 11:00:00']);
    $next = News::factory()->create();
    $this->postJson("/manage-news/{$next->id}/activate", newsActionData($next))->assertOk();
    expect(app(HomepageNewsService::class)->current()->id)->toBe($temporary->id);
    $this->travelTo(CarbonImmutable::parse('2030-01-15T11:00:00Z'));
    expect(app(HomepageNewsService::class)->current()->id)->toBe($next->id)
        ->and($temporary->fresh()->status($next->id, CarbonImmutable::now()))->toBe('Expired');
    $this->postJson("/manage-news/{$temporary->id}/schedule", newsActionData($temporary))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

it('archives an active override and restores it as an unpublished draft with its period retained', function (): void {
    $temporary = News::factory()->scheduled()->create(['starts_at' => '2030-01-15 09:00:00', 'ends_at' => '2030-01-15 11:00:00']);
    $regular = HomepageNewsState::findOrFail(1)->active_news_id;
    $this->postJson("/manage-news/{$temporary->id}/archive", newsActionData($temporary))->assertOk();
    expect(app(HomepageNewsService::class)->current()->id)->toBe($regular);
    $this->postJson("/manage-news/{$temporary->id}/restore", newsActionData($temporary))->assertOk();
    expect($temporary->fresh()->is_scheduled)->toBeFalse()->and($temporary->fresh()->starts_at->format('H:i'))->toBe('09:00');
});

it('allows converting an unpublished draft between the two types', function (): void {
    $news = News::factory()->temporary()->create();
    $this->patchJson("/manage-news/{$news->id}", newsInput(['revision' => 1]))->assertOk();
    expect($news->fresh()->kind)->toBe(NewsKind::REGULAR)->and($news->fresh()->starts_at)->toBeNull();
    $this->patchJson("/manage-news/{$news->id}", newsInput(['revision' => 2, 'kind' => 'temporary', 'starts_at' => '2030-01-20T12:00', 'ends_at' => '2030-01-20T13:00']))->assertOk();
    expect($news->fresh()->kind)->toBe(NewsKind::TEMPORARY);
});

it('switches directly to an adjacent temporary announcement', function (): void {
    News::factory()->scheduled()->create(['starts_at' => '2030-01-15 09:00:00', 'ends_at' => '2030-01-15 10:00:00']);
    $next = News::factory()->scheduled()->create(['starts_at' => '2030-01-15 10:00:00', 'ends_at' => '2030-01-15 11:00:00']);
    expect(app(HomepageNewsService::class)->current()->id)->toBe($next->id);
});

it('plans one-hour overrides across Berlin clock changes including repeated wall times', function (string $start, string $end, string $startOffset, string $endOffset, string $during, string $after): void {
    $id = $this->postJson('/manage-news', newsInput([
        'kind' => 'temporary', 'starts_at' => $start, 'ends_at' => $end,
        'starts_at_offset' => $startOffset, 'ends_at_offset' => $endOffset,
    ]))->assertCreated()->json('id');
    $news = News::findOrFail($id);
    expect($news->ends_at->getTimestamp() - $news->starts_at->getTimestamp())->toBe(3600);
    $this->postJson("/manage-news/{$id}/schedule", newsActionData($news))->assertOk();
    $this->travelTo(CarbonImmutable::parse($during));
    expect(app(HomepageNewsService::class)->current()->id)->toBe($id);
    $this->travelTo(CarbonImmutable::parse($after));
    expect(app(HomepageNewsService::class)->current()->id)->toBe(HomepageNewsState::findOrFail(1)->active_news_id);
})->with([
    ['2030-03-31T01:30', '2030-03-31T03:30', '+01:00', '+02:00', '2030-03-31T01:00:00Z', '2030-03-31T01:30:00Z'],
    ['2030-10-27T02:30', '2030-10-27T02:30', '+02:00', '+01:00', '2030-10-27T01:00:00Z', '2030-10-27T01:30:00Z'],
]);
