<?php

declare(strict_types=1);

use App\Models\HomepageNewsState;
use App\Models\News;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('enforces publication shapes at database level', function (array $attributes): void {
    $news = News::factory()->create();
    $this->expectException(QueryException::class);
    DB::table('news')->where('id', $news->id)->update($attributes);
})->with([
    'unknown type' => [['kind' => 'unknown']],
    'scheduled regular' => [['is_scheduled' => 1]],
    'regular with interval' => [['starts_at' => '2030-01-20 10:00:00']],
    'missing interval' => [['kind' => 'temporary']],
    'half interval' => [['kind' => 'temporary', 'starts_at' => '2030-01-20 10:00:00']],
    'inverted interval' => [['kind' => 'temporary', 'starts_at' => '2030-01-20 11:00:00', 'ends_at' => '2030-01-20 10:00:00']],
    'archived scheduled' => [['kind' => 'temporary', 'starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 11:00:00', 'is_scheduled' => 1, 'archived_at' => '2030-01-20 09:00:00']],
    'invalid revision' => [['revision' => 0]],
]);

it('requires one fixed selection row and a non-null valid news reference', function (array $row): void {
    $this->expectException(QueryException::class);
    DB::table('homepage_news_state')->insert($row);
})->with([
    'second singleton' => [['id' => 2, 'active_news_id' => 1, 'revision' => 1]],
    'duplicate singleton' => [['id' => 1, 'active_news_id' => 1, 'revision' => 1]],
]);

it('does not permit removing or clearing the active news reference', function (string $operation): void {
    $state = HomepageNewsState::findOrFail(1);
    $this->expectException(QueryException::class);
    match ($operation) {
        'delete' => DB::table('news')->where('id', $state->active_news_id)->delete(),
        'clear' => DB::table('homepage_news_state')->where('id', 1)->update(['active_news_id' => null]),
        'missing' => DB::table('homepage_news_state')->where('id', 1)->update(['active_news_id' => 999999]),
    };
})->with(['delete', 'clear', 'missing']);
