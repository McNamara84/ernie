<?php

declare(strict_types=1);

use App\Enums\NewsKind;
use App\Models\HomepageNewsState;
use App\Models\News;
use App\Models\User;
use App\Services\News\NewsManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses()->group('mysql-sensitive');

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the pinned MySQL 9.7 test server.');
    }
    $this->travelTo(CarbonImmutable::parse('2030-01-15T10:00:00Z'));
});

it('waits for the publication lock and validates the newly committed state', function (string $operation): void {
    $actor = User::factory()->admin()->create();
    $original = HomepageNewsState::findOrFail(1);
    $first = $operation === 'schedule' || $operation === 'period-edit'
        ? News::factory()->temporary()->create(['starts_at' => '2030-01-20 10:00:00', 'ends_at' => '2030-01-20 12:00:00'])
        : News::factory()->create();
    $second = $operation === 'schedule' || $operation === 'period-edit'
        ? News::factory()->temporary()->create(['starts_at' => '2030-01-20 11:00:00', 'ends_at' => '2030-01-20 13:00:00'])
        : News::factory()->create();
    if ($operation === 'period-edit') {
        $first->update(['is_scheduled' => true, 'ends_at' => '2030-01-20 11:00:00']);
        $second->update(['is_scheduled' => true, 'starts_at' => '2030-01-20 12:00:00', 'ends_at' => '2030-01-20 13:00:00']);
    }
    DB::commit();
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Could not start concurrent news writer.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        stream_set_timeout($pair[1], 20);
        fgets($pair[1]);
        try {
            DB::connection()->beforeExecuting(function (string $query) use ($pair): void {
                if (str_contains($query, 'homepage_news_state') && str_contains($query, 'for update')) {
                    fwrite($pair[1], "locking\n");
                }
            });
            $service = app(NewsManagementService::class);
            match ($operation) {
                // Use the expected post-commit revision so overlap validation itself is exercised.
                'schedule' => $service->action($actor, $second->id, 1, $original->revision + 1, 'schedule'),
                'activate' => $service->action($actor, $second->id, 1, $original->revision, 'activate'),
                'archive' => $service->action($actor, $first->id, 2, $original->revision + 1, 'archive'),
                'stale-edit' => $service->save($actor, ['title' => 'Stale text', 'body_markdown' => 'Stale', 'kind' => NewsKind::REGULAR, 'starts_at' => null, 'ends_at' => null], $first->id, 1),
                'period-edit' => $service->save($actor, ['title' => 'Changed interval', 'body_markdown' => 'Changed', 'kind' => NewsKind::TEMPORARY, 'starts_at' => CarbonImmutable::parse('2030-01-20T11:30:00Z'), 'ends_at' => CarbonImmutable::parse('2030-01-20T13:00:00Z')], $second->id, 1),
            };
            fwrite($pair[1], "200\n");
            exit(0);
        } catch (ValidationException $exception) {
            fwrite($pair[1], $exception->status."\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($pair[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 20);
    try {
        DB::beginTransaction();
        HomepageNewsState::query()->lockForUpdate()->findOrFail(1);
        fwrite($pair[0], "go\n");
        expect(trim((string) fgets($pair[0])))->toBe('locking');
        $read = [$pair[0]];
        $write = $except = null;
        expect(stream_select($read, $write, $except, 0, 200000))->toBe(0);
        $service = app(NewsManagementService::class);
        match ($operation) {
            'schedule' => $service->action($actor, $first->id, 1, $original->revision, 'schedule'),
            'activate', 'archive' => $service->action($actor, $first->id, 1, $original->revision, 'activate'),
            'stale-edit' => $service->save($actor, ['title' => 'Latest text', 'body_markdown' => 'Latest', 'kind' => NewsKind::REGULAR, 'starts_at' => null, 'ends_at' => null], $first->id, 1),
            'period-edit' => $service->save($actor, ['title' => 'Edited first', 'body_markdown' => 'Text', 'kind' => NewsKind::TEMPORARY, 'starts_at' => CarbonImmutable::parse('2030-01-20T10:00:00Z'), 'ends_at' => CarbonImmutable::parse('2030-01-20T12:00:00Z')], $first->id, 1),
        };
        DB::commit();
        expect(trim((string) fgets($pair[0])))->toBe(in_array($operation, ['activate', 'stale-edit'], true) ? '409' : '422');
        pcntl_waitpid($pid, $exit);
        expect(pcntl_wexitstatus($exit))->toBe(0);
        DB::purge();
        expect(HomepageNewsState::count())->toBe(1)->and(HomepageNewsState::findOrFail(1)->active_news_id)->toBe(in_array($operation, ['activate', 'archive'], true) ? $first->id : $original->active_news_id);
        if ($operation === 'schedule') {
            expect(News::where('is_scheduled', true)->count())->toBe(1)->and($second->fresh()->is_scheduled)->toBeFalse();
        } elseif ($operation === 'stale-edit') {
            expect($first->fresh()->title)->toBe('Latest text');
        } elseif ($operation === 'archive') {
            expect($first->fresh()->archived_at)->toBeNull();
        } elseif ($operation === 'period-edit') {
            expect($second->fresh()->starts_at->format('H:i'))->toBe('12:00');
        }
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        pcntl_waitpid($pid, $exit, WNOHANG);
        DB::purge();
        HomepageNewsState::findOrFail(1)->update(['active_news_id' => $original->active_news_id, 'revision' => $original->revision]);
        News::whereKey([$first->id, $second->id])->delete();
        User::whereKey($actor->id)->delete();
        DB::beginTransaction();
    }
})->with(['schedule', 'activate', 'archive', 'stale-edit', 'period-edit']);
