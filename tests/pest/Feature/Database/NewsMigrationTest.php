<?php

declare(strict_types=1);

use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('mysql-sensitive');

it('creates and rolls back the news schema without changing existing users', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the pinned MySQL 9.7 test server.');
    }
    $actor = User::factory()->admin()->create();
    $originalTitle = News::firstOrFail()->title;
    $schema = require database_path('migrations/2026_11_18_000001_create_news_tables.php');
    $seed = require database_path('migrations/2026_11_18_000002_seed_homepage_news.php');
    DB::commit();
    try {
        $seed->down();
        $schema->down();
        expect(Schema::hasTable('news'))->toBeFalse()->and(User::find($actor->id))->not->toBeNull();
        $schema->up();
        $seed->up();
        expect(News::count())->toBe(1)->and(HomepageNewsState::findOrFail(1)->active_news_id)->toBe(News::firstOrFail()->id)
            ->and(Schema::hasIndex('news', 'news_schedule_lookup'))->toBeTrue();
        News::firstOrFail()->update(['title' => 'Editorial changes']);
        $seed->up();
        expect(News::firstOrFail()->title)->toBe('Editorial changes');
    } finally {
        if (! Schema::hasTable('news')) {
            $schema->up();
        }
        $seed->up();
        News::firstOrFail()->update(['title' => $originalTitle]);
        User::whereKey($actor->id)->delete();
        DB::beginTransaction();
    }
});
