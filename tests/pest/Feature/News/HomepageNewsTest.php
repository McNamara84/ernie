<?php

declare(strict_types=1);

use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use App\Services\News\NewsMarkdownRendererService;
use Inertia\Testing\AssertableInertia as Assert;

it('migrates the complete original announcement and safely renders it from the database', function (): void {
    $original = News::findOrFail(HomepageNewsState::findOrFail(1)->active_news_id);
    expect($original->title)->toBe('Welcome ELMO - our new Metadata Editor!')
        ->and($original->body_markdown)->toBe('In November 2025, the GFZ Data Services team proudly launched the new, fully revised and modernised version of our [new metadata editor ELMO](https://dataservices.gfz.de/elmo). ELMO is not only a new web interface, but also contains many revised functionalities that increase the quality of metadata and the FAIRness of the data describing it, while at the same time simplifying the entry of information for researchers.')
        ->and($original->is_scheduled)->toBeFalse()->and($original->archived_at)->toBeNull();
    $this->get('/')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
        ->where('news.id', $original->id)->where('news.title', $original->title)
        ->where('news.html', app(NewsMarkdownRendererService::class)->render($original->body_markdown))
        ->missing('news.body_markdown')->missing('news.revision')->missing('news.created_by_user_id'));
});

it('serves changed active content immediately while keeping other content private', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }
    News::factory()->create(['title' => 'Secret draft']);
    News::factory()->archived()->create(['title' => 'Secret archive']);
    $active = News::findOrFail(HomepageNewsState::findOrFail(1)->active_news_id);
    $active->update(['title' => 'Database announcement', 'body_markdown' => '**Database content**']);
    $this->get('/')->assertOk()->assertDontSee('Secret draft')->assertDontSee('Secret archive')
        ->assertInertia(fn (Assert $page) => $page->where('news.title', 'Database announcement')->where('news.html', "<p><strong>Database content</strong></p>\n"));
})->with([false, true]);

it('keeps editorial changes when the initial data migration is repeated', function (): void {
    $news = News::factory()->create(['title' => 'Keep this selection']);
    HomepageNewsState::findOrFail(1)->update(['active_news_id' => $news->id, 'revision' => 7]);
    $migration = require database_path('migrations/2026_11_18_000002_seed_homepage_news.php');
    $migration->up();
    $migration->down();
    expect(News::count())->toBe(2)->and(HomepageNewsState::findOrFail(1)->active_news_id)->toBe($news->id)
        ->and(HomepageNewsState::findOrFail(1)->revision)->toBe(7);
});
