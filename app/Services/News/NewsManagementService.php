<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsKind;
use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class NewsManagementService
{
    /** @param array{title: string, body_markdown: string, kind: NewsKind, starts_at: CarbonImmutable|null, ends_at: CarbonImmutable|null} $data */
    public function save(User $actor, array $data, ?int $id = null, ?int $revision = null): News
    {
        return $this->write($actor, $id, $revision, null, function (HomepageNewsState $state, News $news) use ($data): void {
            $this->require($news->archived_at === null, 'Restore this news before editing it.');
            if ($news->exists && $news->kind !== $data['kind']) {
                $this->require($state->active_news_id !== $news->id && ! $news->is_scheduled, 'Replace the active news or cancel its schedule before changing its type.');
            }
            $news->fill($data);
            if ($news->kind === NewsKind::TEMPORARY) {
                $this->require($news->starts_at !== null && $news->ends_at !== null && $news->ends_at > $news->starts_at, 'The end must be after the start.', 'ends_at');
            }
            if ($news->is_scheduled) {
                $this->checkOverlap($news);
            }
        });
    }

    public function action(User $actor, int $id, int $revision, int $stateRevision, string $operation): News
    {
        return $this->write($actor, $id, $revision, $stateRevision, function (HomepageNewsState $state, News $news) use ($operation): void {
            if ($operation === 'restore') {
                $this->require($news->archived_at !== null, 'This news is not archived.');
                $news->archived_at = null;
                $news->is_scheduled = false;

                return;
            }
            $this->require($news->archived_at === null, 'Restore this news first.');
            switch ($operation) {
                case 'activate':
                    $this->require($news->kind === NewsKind::REGULAR, 'Only regular news can be selected as the active regular news.');
                    $state->active_news_id = $news->id;
                    break;
                case 'schedule':
                    $this->require($news->kind === NewsKind::TEMPORARY, 'Only temporary news can be scheduled.');
                    $this->require($news->ends_at !== null && $news->ends_at > CarbonImmutable::now('UTC'), 'Choose an end in the future before scheduling this news.', 'ends_at');
                    $this->checkOverlap($news);
                    $news->is_scheduled = true;
                    break;
                case 'cancel-schedule':
                    $this->require($news->is_scheduled, 'This news has no schedule.');
                    $news->is_scheduled = false;
                    break;
                case 'archive':
                    $this->require($state->active_news_id !== $news->id, 'Select another regular news before archiving this one.');
                    $news->is_scheduled = false;
                    $news->archived_at = CarbonImmutable::now('UTC');
                    break;
                default:
                    abort(404);
            }
        });
    }

    /** @param Closure(HomepageNewsState, News): void $change */
    private function write(User $actor, ?int $id, ?int $revision, ?int $stateRevision, Closure $change): News
    {
        Gate::forUser($actor)->authorize('manage-news');

        return DB::transaction(function () use ($actor, $id, $revision, $stateRevision, $change): News {
            // Always lock this existing row first, including when no temporary news exist yet.
            $state = HomepageNewsState::query()->lockForUpdate()->findOrFail(1);
            $news = $id === null ? new News(['is_scheduled' => false]) : News::query()->lockForUpdate()->findOrFail($id);
            if (($stateRevision !== null && $stateRevision !== $state->revision) || ($news->exists && $revision !== $news->revision)) {
                throw ValidationException::withMessages(['revision' => 'News changed in another session. Your edits are kept; reload the latest version before trying again.'])->status(409);
            }
            $change($state, $news);
            if (! $news->exists) {
                $news->created_by_user_id = $actor->id;
            }
            $news->updated_by_user_id = $actor->id;
            $news->revision = $news->exists ? $news->revision + 1 : 1;
            $news->save();
            $state->revision++;
            $state->save();

            return $news;
        }, attempts: 3);
    }

    private function checkOverlap(News $news): void
    {
        $conflict = News::query()->where('kind', NewsKind::TEMPORARY)->where('is_scheduled', true)->whereNull('archived_at')
            ->when($news->exists, fn ($query) => $query->where('id', '!=', $news->id))
            ->where('starts_at', '<', $news->ends_at)->where('ends_at', '>', $news->starts_at)
            ->orderBy('id')->lockForUpdate()->first();
        if ($conflict !== null) {
            $start = $conflict->starts_at?->setTimezone(NewsLocalTimeService::ZONE)->format('d.m.Y H:i P');
            $end = $conflict->ends_at?->setTimezone(NewsLocalTimeService::ZONE)->format('d.m.Y H:i P');
            throw ValidationException::withMessages(['starts_at' => "This period overlaps with \"{$conflict->title}\" ({$start} – {$end}, Europe/Berlin)."]);
        }
    }

    private function require(bool $condition, string $message, string $field = 'news'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
