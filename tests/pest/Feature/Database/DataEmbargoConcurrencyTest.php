<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\EditorDraftSaveIntent;
use App\Exceptions\DataCiteWriteSupersededException;
use App\Jobs\SendDataEmbargoNotificationJob;
use App\Mail\DataEmbargoExpired;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;
use App\Services\DataCiteDoiWriteLockService;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataEmbargoReleaseService;
use App\Services\DataPublicationTeamRecipientService;
use App\Services\Editor\EditorResourceSaveService;
use App\Services\EmbargoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses()->group('mysql-sensitive');

it('serializes editor embargo changes after an in-flight remote PUT across independent workers', function (): void {
    expect(DB::selectOne('SELECT VERSION() AS version')->version)->toStartWith('9.7.');
    config([
        'datacite.test_mode' => true,
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'cache.stores.embargo-concurrency' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'cache', 'prefix' => 'embargo-review:'.Str::uuid().':'],
    ]);
    $cacheManager = Cache::getFacadeRoot();
    $cache = $cacheManager->store('embargo-concurrency');
    Cache::swap($cache);
    $resource = Resource::factory()->create(['doi' => '10.83279/concurrent-embargo-'.Str::uuid(), 'access_level' => AccessLevel::OPEN, 'data_embargo_sync_pending' => true]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Concurrent embargo metadata']);
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => '2020-01-01']);
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => true]);
    Queue::fake();
    DB::commit();
    DB::disconnect();
    app('redis')->purge('cache');
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Unable to start editor worker.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        stream_set_timeout($sockets[1], 15);
        try {
            DB::purge();
            if (fgets($sockets[1]) !== "start\n") {
                throw new RuntimeException('The remote writer did not start.');
            }
            fwrite($sockets[1], "editing\n");
            app(EditorResourceSaveService::class)->saveRelaxed([
                'resourceId' => $resource->id,
                'accessLevel' => 'open',
                'titles' => [['title' => 'New embargo metadata', 'titleType' => 'main-title']],
                'dates' => [['dateType' => 'available', 'startDate' => '2099-01-01']],
            ], null, EditorDraftSaveIntent::AUTOSAVE);
            fwrite($sockets[1], "saved\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 15);
    try {
        Http::fake(function ($request) use ($resource, $sockets) {
            if ($request->method() === 'PUT') {
                expect(DataCiteDoiWriteLockService::hasHeldLock())->toBeTrue();
                fwrite($sockets[0], "start\n");
                expect(fgets($sockets[0]))->toBe("editing\n");
                $read = [$sockets[0]];
                $write = $except = [];
                expect(stream_select($read, $write, $except, 0, 250000))->toBe(0)
                    ->and($resource->fresh()->access_level)->toBe(AccessLevel::OPEN);
            }

            return Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]]);
        });
        try {
            app(DataEmbargoReleaseService::class)->synchronize($resource, new DataCiteMemberApiClient(new DataCiteRequestLimiter, true));
        } catch (DataCiteWriteSupersededException) {
            // The editor may acquire the lock before the acknowledgement. Its
            // corrective job is covered separately; either ordering is valid.
        }
        expect(fgets($sockets[0]))->toBe("saved\n");
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
    } finally {
        fclose($sockets[0]);
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        Resource::whereKey($resource->id)->delete();
        foreach (['request-history-ms', 'background-history-ms', 'interactive-waiting-until-ms', 'cooldown-until-ms'] as $key) {
            $cache->forget('datacite:member-api:'.$key);
        }
        $cache->forget('datacite:doi-write:'.hash('sha256', 'test:'.$resource->doi).':revision');
        Cache::swap($cacheManager);
        app('redis')->purge('cache');
        DB::purge();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires MySQL 9.7 and shared Redis locks.');

it('claims a due notification once when two MySQL workers deliver concurrently', function (): void {
    expect(DB::selectOne('SELECT VERSION() AS version')->version)->toStartWith('9.7.');
    $resource = Resource::factory()->create(['access_level' => AccessLevel::EMBARGOED]);
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => '2020-01-01']);
    $noticeId = DB::table('data_embargo_notifications')->insertGetId([
        'resource_id' => $resource->id, 'available_date' => '2020-01-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::commit();
    DB::disconnect();
    $children = [];
    try {
        for ($worker = 0; $worker < 2; $worker++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create worker sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork notification worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                stream_set_timeout($sockets[1], 20);
                try {
                    DB::purge();
                    Mail::fake();
                    config(['mail.landing_page_contact_cc' => 'team@example.org']);
                    fwrite($sockets[1], "ready\n");
                    if (fgets($sockets[1]) !== "go\n") {
                        throw new RuntimeException('Worker barrier timed out.');
                    }
                    (new SendDataEmbargoNotificationJob($noticeId))->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
                    fwrite($sockets[1], (string) Mail::sent(DataEmbargoExpired::class)->count()."\n");
                    fclose($sockets[1]);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 25);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        foreach ($children as $child) {
            expect(fgets($child['socket']))->toBe("ready\n");
        }
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        $delivered = 0;
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->toBeIn(["0\n", "1\n"]);
            $delivered += (int) $line;
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        expect($delivered)->toBe(1)
            ->and(DB::table('data_embargo_notifications')->where('id', $noticeId)->value('status'))->toBe('sent');
    } finally {
        foreach ($children as $child) {
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
        }
        Resource::whereKey($resource->id)->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 test server.');
