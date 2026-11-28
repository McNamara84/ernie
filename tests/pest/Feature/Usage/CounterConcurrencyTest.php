<?php

use App\Models\LandingPage;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterEventRecorderService;
use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses()->group('mysql-sensitive');

/**
 * Fork real independent database connections and coordinate them through pipes.
 * The test fixture commits only in the dedicated MySQL test database.
 */
it('serializes legacy activation and concurrent daily finalization on MySQL', function () {
    config(['usage.session_secret' => str_repeat('concurrent-counter-test', 2)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    $original = (array) DB::table('usage_tracking_state')->find(1);
    $page = LandingPage::factory()->published()->withDoi('10.5880/counter-lock')->create(['view_count' => 0]);
    $resourceId = $page->resource_id;
    $versions = DB::table('usage_list_versions')->pluck('id')->all();
    DB::commit();
    DB::disconnect();
    $workers = [];
    $spawn = function (Closure $work) use (&$workers): int {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create worker pipes.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 20);
            try {
                DB::purge();
                $work($sockets[1]);
                fwrite($sockets[1], "done\n");
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($sockets[1], 'error:'.$exception->getMessage()."\n");
                fclose($sockets[1]);
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 30);
        $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];

        return array_key_last($workers);
    };
    try {
        $writer = $spawn(function ($socket) use ($page): void {
            app(CounterTrackingStateService::class)->legacy(function () use ($socket, $page): void {
                fwrite($socket, "locked\n");
                if (fgets($socket) !== "write\n") {
                    throw new RuntimeException('Writer barrier failed.');
                }
                $page->incrementViewCount();
            });
        });
        expect(fgets($workers[$writer]['socket']))->toBe("locked\n");
        $activation = $spawn(function ($socket): void {
            fwrite($socket, "activating\n");
            app(CounterTrackingStateService::class)->activate();
        });
        expect(fgets($workers[$activation]['socket']))->toBe("activating\n");
        $read = [$workers[$activation]['socket']];
        $write = $except = [];
        expect(stream_select($read, $write, $except, 0, 200_000))->toBe(0);
        fwrite($workers[$writer]['socket'], "write\n");
        expect(fgets($workers[$writer]['socket']))->toBe("done\n");
        expect(fgets($workers[$activation]['socket']))->toBe("done\n");
        DB::purge();
        $page->refresh()->incrementViewCount();
        expect($page->fresh()->view_count)->toBe(1);

        app(CounterEventRecorderService::class)->landingPage(
            Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130', 'REMOTE_ADDR' => '192.0.2.5']),
            $page, 'investigation', 'landing-page',
        );
        expect(DB::table('usage_events')->count())->toBe(1);
        $this->travel(1)->days();
        DB::disconnect();
        $aggregators = [];
        for ($i = 0; $i < 2; $i++) {
            $aggregators[] = $spawn(function ($socket): void {
                fwrite($socket, "ready\n");
                if (fgets($socket) !== "go\n") {
                    throw new RuntimeException('Aggregation barrier failed.');
                }
                fwrite($socket, app(CounterAggregationService::class)->aggregate()."\n");
            });
        }
        foreach ($aggregators as $index) {
            expect(fgets($workers[$index]['socket']))->toBe("ready\n");
        }
        foreach ($aggregators as $index) {
            fwrite($workers[$index]['socket'], "go\n");
        }
        $counts = [];
        foreach ($aggregators as $index) {
            $counts[] = (int) trim((string) fgets($workers[$index]['socket']));
            expect(fgets($workers[$index]['socket']))->toBe("done\n");
        }
        sort($counts);
        expect($counts)->toBe([0, 1]);
        DB::purge();
        expect(DB::table('usage_events')->count())->toBe(0)
            ->and((int) DB::table('usage_daily_metrics')->sum('unique_investigations'))->toBe(1)
            ->and((int) DB::table('usage_monthly_metrics')->sum('unique_investigations'))->toBe(1)
            ->and((int) DB::table('usage_tracking_state')->find(1)->metrics_revision)->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
        }
        DB::purge();
        foreach (['usage_events', 'usage_daily_metrics', 'usage_monthly_metrics'] as $table) {
            DB::table($table)->where('landing_page_id', $page->id)->delete();
        }
        DB::table('usage_aggregation_days')->where('day', '2026-10-01')->delete();
        DB::table('usage_tracking_state')->where('id', 1)->update($original);
        DB::table('usage_list_versions')->whereNotIn('id', $versions)->delete();
        DB::table('landing_pages')->where('id', $page->id)->delete();
        DB::table('resources')->where('id', $resourceId)->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork'), 'Requires the pinned MySQL 9.7 test server and pcntl.');
