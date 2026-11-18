<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use App\Services\News\HomepageNewsService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/NewsFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-15T10:00:00Z'));
});

it('allows both authorized roles to create edit preview activate plan cancel archive and restore news', function (UserRole $role): void {
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor)->get('/manage-news')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('manage-news/index')->has('news', 1)->where('auth.user.can_manage_news', true));
    $original = HomepageNewsState::findOrFail(1)->active_news_id;
    $id = $this->postJson('/manage-news', newsInput(['is_scheduled' => true, 'archived_at' => '2000-01-01', 'created_by_user_id' => 999, 'revision' => 9]))->assertCreated()->json('id');
    $news = News::findOrFail($id);
    expect($news->is_scheduled)->toBeFalse()->and($news->archived_at)->toBeNull()
        ->and($news->revision)->toBe(1)->and($news->created_by_user_id)->toBe($actor->id)
        ->and(HomepageNewsState::findOrFail(1)->active_news_id)->toBe($original);

    $this->patchJson("/manage-news/{$id}", newsInput(['title' => 'Edited news', 'revision' => 1]))->assertOk();
    expect($news->fresh()->title)->toBe('Edited news')->and($news->fresh()->revision)->toBe(2);
    $this->postJson('/manage-news/preview', ['body_markdown' => '**Preview**'])->assertOk()->assertJsonPath('html', "<p><strong>Preview</strong></p>\n");
    $this->postJson("/manage-news/{$id}/activate", newsActionData($news))->assertOk();
    expect(app(HomepageNewsService::class)->current()->id)->toBe($id);
    $this->postJson("/manage-news/{$original}/activate", newsActionData(News::findOrFail($original)))->assertOk();
    $this->postJson("/manage-news/{$id}/archive", newsActionData($news))->assertOk();
    expect($news->fresh()->archived_at)->not->toBeNull();
    $this->postJson("/manage-news/{$id}/restore", newsActionData($news))->assertOk();
    expect($news->fresh()->status($original, CarbonImmutable::now()))->toBe('Draft');

    $temporaryId = $this->postJson('/manage-news', newsInput([
        'kind' => 'temporary', 'starts_at' => '2030-01-15T10:00', 'ends_at' => '2030-01-15T13:00',
    ]))->assertCreated()->json('id');
    $temporary = News::findOrFail($temporaryId);
    expect($temporary->starts_at->toIso8601String())->toBe('2030-01-15T09:00:00+00:00');
    $this->postJson("/manage-news/{$temporaryId}/schedule", newsActionData($temporary))->assertOk();
    expect(app(HomepageNewsService::class)->current()->id)->toBe($temporaryId);
    $this->postJson("/manage-news/{$temporaryId}/cancel-schedule", newsActionData($temporary))->assertOk();
    expect($temporary->fresh()->is_scheduled)->toBeFalse()->and(app(HomepageNewsService::class)->current()->id)->toBe($original);
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('protects every management endpoint against unauthorized roles and inactive accounts', function (UserRole $role, bool $active): void {
    $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
    $id = News::firstOrFail()->id;
    $this->actingAs($user)->get('/manage-news')->assertForbidden();
    foreach (['', '/preview', ...array_map(fn (string $action): string => "/{$id}/{$action}", ['activate', 'schedule', 'cancel-schedule', 'archive', 'restore'])] as $path) {
        $this->postJson('/manage-news'.$path, [])->assertForbidden();
    }
    $this->patchJson("/manage-news/{$id}", [])->assertForbidden();
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.can_manage_news', false));
})->with([
    'curator' => [UserRole::CURATOR, true], 'beginner' => [UserRole::BEGINNER, true],
    'inactive admin' => [UserRole::ADMIN, false], 'inactive leader' => [UserRole::GROUP_LEADER, false],
]);

it('requires authentication and follows the existing application verification behavior', function (): void {
    $id = News::firstOrFail()->id;
    $this->get('/manage-news')->assertRedirect('/login');
    $this->postJson('/manage-news/preview')->assertUnauthorized();
    $this->postJson('/manage-news', newsInput())->assertUnauthorized();
    $this->patchJson("/manage-news/{$id}", newsInput())->assertUnauthorized();
    foreach (['activate', 'schedule', 'cancel-schedule', 'archive', 'restore'] as $action) {
        $this->postJson("/manage-news/{$id}/{$action}", [])->assertUnauthorized();
    }
    // User does not implement MustVerifyEmail; the shared verified middleware is currently a no-op.
    $this->actingAs(User::factory()->admin()->unverified()->create())->get('/manage-news')->assertOk();
});

it('validates title body kind periods and revisions', function (array $data, string $field): void {
    $this->actingAs(User::factory()->admin()->create())->postJson('/manage-news', newsInput($data))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty title' => [['title' => '   '], 'title'],
    'long title' => [['title' => str_repeat('a', 256)], 'title'],
    'empty body' => [['body_markdown' => ''], 'body_markdown'],
    'long body' => [['body_markdown' => str_repeat('x', 100001)], 'body_markdown'],
    'invalid kind' => [['kind' => 'other'], 'kind'],
    'regular interval' => [['starts_at' => '2030-01-20T12:00'], 'starts_at'],
    'missing dates' => [['kind' => 'temporary'], 'starts_at'],
    'invalid date' => [['kind' => 'temporary', 'starts_at' => '2030-02-30T10:00', 'ends_at' => '2030-03-01T10:00'], 'starts_at'],
    'equal dates' => [['kind' => 'temporary', 'starts_at' => '2030-01-20T12:00', 'ends_at' => '2030-01-20T12:00'], 'ends_at'],
    'reversed dates' => [['kind' => 'temporary', 'starts_at' => '2030-01-20T13:00', 'ends_at' => '2030-01-20T12:00'], 'ends_at'],
    'invalid offset' => [['kind' => 'temporary', 'starts_at' => '2030-01-20T12:00', 'ends_at' => '2030-01-20T13:00', 'starts_at_offset' => '+03:00'], 'starts_at_offset'],
    'incorrect seasonal offset' => [['kind' => 'temporary', 'starts_at' => '2030-01-20T12:00', 'ends_at' => '2030-01-20T13:00', 'starts_at_offset' => '+02:00'], 'starts_at_offset'],
]);

it('protects the active regular news and rejects invalid actions', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $active = News::findOrFail(HomepageNewsState::findOrFail(1)->active_news_id);
    foreach (['archive', 'schedule', 'cancel-schedule', 'restore'] as $action) {
        $this->postJson("/manage-news/{$active->id}/{$action}", newsActionData($active))->assertUnprocessable();
    }
    $this->patchJson("/manage-news/{$active->id}", newsInput([
        'revision' => 1, 'kind' => 'temporary', 'starts_at' => '2030-01-20T12:00', 'ends_at' => '2030-01-20T13:00',
    ]))->assertUnprocessable();
    $temporary = News::factory()->temporary()->create();
    $this->postJson("/manage-news/{$temporary->id}/activate", newsActionData($temporary))->assertUnprocessable();
    $archived = News::factory()->archived()->create();
    foreach (['activate', 'schedule', 'archive', 'cancel-schedule'] as $action) {
        $this->postJson("/manage-news/{$archived->id}/{$action}", newsActionData($archived))->assertUnprocessable();
    }
    $this->patchJson("/manage-news/{$archived->id}", newsInput(['revision' => 1]))->assertUnprocessable();
    expect(HomepageNewsState::findOrFail(1)->active_news_id)->toBe($active->id)->and($active->fresh()->revision)->toBe(1);
});

it('detects stale forms and publication state without losing newer data', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $news = News::factory()->create();
    $stale = newsActionData($news);
    $this->patchJson("/manage-news/{$news->id}", newsInput(['revision' => 1, 'title' => 'Latest text']))->assertOk();
    $this->patchJson("/manage-news/{$news->id}", newsInput(['revision' => 1]))->assertStatus(409)->assertJsonValidationErrors('revision');
    $this->postJson("/manage-news/{$news->id}/activate", $stale)->assertStatus(409);
    $this->postJson("/manage-news/{$news->id}/activate", ['revision' => 2, 'state_revision' => $stale['state_revision']])->assertStatus(409);
    $this->patchJson("/manage-news/{$news->id}", newsInput())->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->postJson("/manage-news/{$news->id}/archive", [])->assertUnprocessable()->assertJsonValidationErrors(['revision', 'state_revision']);
    expect($news->fresh()->title)->toBe('Latest text')->and($news->fresh()->revision)->toBe(2);
});

it('does not expose delete operations or accept unknown records', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $this->deleteJson('/manage-news/1')->assertStatus(405);
    $this->postJson('/manage-news/999999/activate', ['revision' => 1, 'state_revision' => 1])->assertNotFound();
    $this->patchJson('/manage-news/999999', newsInput(['revision' => 1]))->assertNotFound();
});

it('allows previewing empty content but enforces preview limits', function (): void {
    $this->actingAs(User::factory()->admin()->create())->postJson('/manage-news/preview', [])->assertOk()->assertJson(['html' => '']);
    $this->postJson('/manage-news/preview', ['body_markdown' => str_repeat('a', 100001)])->assertUnprocessable();
});

it('preserves significant Markdown whitespace in storage and preview', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $markdown = "    Indented code\n\nA line with a hard break.  \nNext line.\n";
    $id = $this->postJson('/manage-news', newsInput(['body_markdown' => $markdown]))->assertCreated()->json('id');
    expect(News::findOrFail($id)->body_markdown)->toBe($markdown);
    $this->postJson('/manage-news/preview', ['body_markdown' => $markdown])->assertOk()
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, '<pre><code>Indented code') && str_contains($html, '<br />'));
});

it('retains news when their author is removed', function (): void {
    $author = User::factory()->admin()->create();
    $news = News::factory()->create(['created_by_user_id' => $author->id, 'updated_by_user_id' => $author->id]);
    $author->delete();
    expect($news->fresh()->created_by_user_id)->toBeNull()->and($news->fresh()->updated_by_user_id)->toBeNull();
});

it('lists every publication status with unambiguous Berlin dates for authorized editors', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    News::factory()->create();
    News::factory()->scheduled()->create(['starts_at' => '2030-01-16 10:00:00', 'ends_at' => '2030-01-16 11:00:00']);
    $current = News::factory()->scheduled()->create(['starts_at' => '2030-01-15 09:00:00', 'ends_at' => '2030-01-15 11:00:00']);
    News::factory()->scheduled()->create(['starts_at' => '2030-01-14 09:00:00', 'ends_at' => '2030-01-14 11:00:00']);
    News::factory()->archived()->create();
    $this->get('/manage-news')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('news', 6)->where('news.0.status', 'Archived')->where('news.1.status', 'Expired')
        ->where('news.2.status', 'Currently displayed')->where('news.3.status', 'Scheduled')
        ->where('news.3.starts_at', '2030-01-16T11:00:00+01:00')
        ->where('news.4.status', 'Draft')->where('news.5.status', 'Active regular')
        ->where('currently_displayed.id', $current->id));
});
