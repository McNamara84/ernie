<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\News\NewsActionRequest;
use App\Http\Requests\News\PreviewNewsRequest;
use App\Http\Requests\News\SaveNewsRequest;
use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use App\Services\News\HomepageNewsService;
use App\Services\News\NewsLocalTimeService;
use App\Services\News\NewsManagementService;
use App\Services\News\NewsMarkdownRendererService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class NewsController extends Controller
{
    public function index(HomepageNewsService $homepage): Response
    {
        return DB::transaction(function () use ($homepage): Response {
            $state = HomepageNewsState::query()->findOrFail(1);
            $now = CarbonImmutable::now('UTC');

            return Inertia::render('manage-news/index', [
                'news' => News::query()->orderByDesc('updated_at')->orderByDesc('id')->get()->map(fn (News $news): array => [
                    'id' => $news->id, 'title' => $news->title, 'body_markdown' => $news->body_markdown,
                    'kind' => $news->kind->value, 'is_scheduled' => $news->is_scheduled,
                    'starts_at' => $news->starts_at?->setTimezone(NewsLocalTimeService::ZONE)->toIso8601String(),
                    'ends_at' => $news->ends_at?->setTimezone(NewsLocalTimeService::ZONE)->toIso8601String(),
                    'archived_at' => $news->archived_at?->toIso8601String(),
                    'revision' => $news->revision, 'status' => $news->status($state->active_news_id, $now),
                    'updated_at' => $news->updated_at?->toIso8601String(),
                ]),
                'active_news_id' => $state->active_news_id, 'state_revision' => $state->revision,
                'currently_displayed' => $homepage->publicData(),
            ]);
        });
    }

    public function store(SaveNewsRequest $request, NewsManagementService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $news = $service->save($actor, $request->newsData());

        return response()->json(['id' => $news->id], 201);
    }

    public function update(SaveNewsRequest $request, News $news, NewsManagementService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $service->save($actor, $request->newsData(), $news->id, $request->integer('revision'));

        return response()->json(['id' => $news->id]);
    }

    public function action(NewsActionRequest $request, News $news, string $operation, NewsManagementService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $service->action($actor, $news->id, $request->integer('revision'), $request->integer('state_revision'), $operation);

        return response()->json(['id' => $news->id]);
    }

    public function preview(PreviewNewsRequest $request, NewsMarkdownRendererService $renderer): JsonResponse
    {
        return response()->json(['html' => $renderer->render($request->string('body_markdown')->toString())]);
    }
}
