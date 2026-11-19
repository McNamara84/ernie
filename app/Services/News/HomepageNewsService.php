<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\News;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class HomepageNewsService
{
    public function __construct(private NewsMarkdownRendererService $renderer) {}

    public function current(): News
    {
        $now = CarbonImmutable::now('UTC');

        // One statement reads the regular selection and any override from the same snapshot.
        return News::query()->select('news.*')
            ->crossJoin('homepage_news_state as selection')
            ->join('news as regular', 'regular.id', '=', 'selection.active_news_id')
            ->where('selection.id', 1)->where('regular.kind', 'regular')->whereNull('regular.archived_at')
            ->whereNull('news.archived_at')
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $temporary) use ($now): void {
                    $temporary->where('news.kind', 'temporary')->where('news.is_scheduled', true)
                        ->where('news.starts_at', '<=', $now)->where('news.ends_at', '>', $now);
                })->orWhereColumn('news.id', 'selection.active_news_id');
            })
            ->orderByRaw("CASE WHEN news.kind = 'temporary' THEN 0 ELSE 1 END")
            ->firstOrFail();
    }

    /** @return array{id: int, title: string, html: string} */
    public function publicData(?News $news = null): array
    {
        $news ??= $this->current();

        return ['id' => $news->id, 'title' => $news->title, 'html' => $this->renderer->render($news->body_markdown)];
    }
}
