<?php

declare(strict_types=1);

use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Jobs\AssessResourceRunItemJob;
use App\Jobs\DiscoverRelationsJob;
use App\Jobs\RefreshPublishedResourceAssessmentJob;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Assessment\AssessmentRunService;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\Assessment\ResourceAssessmentRefreshService;
use App\Services\RelationDiscoveryService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses()->group('mysql-sensitive');

it('persists editor changes on an independent connection while background HTTP is blocked', function (string $kind) {
    $this->withoutVite();
    config([
        'fuji.enabled' => true, 'fuji.base_url' => 'https://fuji.test',
        'fuji.assessment.minimum_interval_ms' => 0,
        'fuji.assessment.requests_per_minute' => 1000,
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.USER', 'datacite.test.password' => 'test-only',
    ]);
    Cache::flush();
    Queue::fake();
    $actor = User::factory()->groupLeader()->create();
    $resource = Resource::factory()->withDoi('10.5880/concurrent-editor')->create([
        'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
    ]);
    $titleType = TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $resource->titles()->create(['value' => 'Before editing', 'title_type_id' => $titleType->id]);
    $page = LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->published()->create();
    $run = null;
    if (in_array($kind, ['assessment', 'assessment and assistance'], true)) {
        $run = AssessmentRun::factory()->create(['status' => AssessmentRunStatus::RUNNING]);
        $item = AssessmentRunItem::factory()->for($run, 'run')->for($resource)->create([
            'identifier' => $resource->doi,
            'status' => AssessmentRunItemStatus::QUEUED,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
        $job = new AssessResourceRunItemJob($item->id);
    } elseif ($kind === 'publication refresh') {
        ResourceAssessment::create(['resource_id' => $resource->id, 'status' => ResourceAssessment::STATUS_COMPLETED]);
        app(ResourceAssessmentRefreshService::class)->request($resource->id);
        $job = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();
    } else {
        $job = new DiscoverRelationsJob((string) Str::uuid());
    }

    // A real child process owns the background connection. While it is stopped
    // inside its external call, the parent performs an authenticated editor save.
    DB::commit();
    DB::disconnect();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false) {
        throw new RuntimeException('Cannot create background synchronization sockets.');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork a background worker.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        try {
            DB::purge();
            $blocked = false;
            $externalCall = function () use (&$blocked, $sockets, $page, $resource): array {
                if (! $blocked) {
                    if (DB::transactionLevel() !== 0) {
                        throw new RuntimeException('Background HTTP is inside a database transaction.');
                    }
                    $blocked = true;
                    fwrite($sockets[1], "waiting-for-provider\n");
                    stream_set_timeout($sockets[1], 15);
                    if (fgets($sockets[1]) === false) {
                        throw new RuntimeException('Editor did not finish before the provider resumed.');
                    }
                }

                return ['score' => 73.0, 'payload' => [], 'resolvedUrl' => $page->public_url, 'normalizedIdentifier' => $resource->doi];
            };
            if ($kind === 'assistance') {
                Http::fake(function () use ($externalCall) {
                    $externalCall();

                    return Http::response(['data' => [], 'result' => []]);
                });
                $job->handle(app(RelationDiscoveryService::class));
            } else {
                Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);
                $fuji = Mockery::mock(FujiAssessmentService::class);
                $fuji->shouldReceive('assessIdentifier')->once()->andReturnUsing($externalCall);
                if ($job instanceof AssessResourceRunItemJob) {
                    $job->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class), app(AssessmentRunService::class));
                } else {
                    $job->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
                }
            }
            fwrite($sockets[1], "completed\n");
            fclose($sockets[1]);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }

    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 15);
    $secondSockets = null;
    $secondPid = null;
    try {
        expect(trim((string) fgets($sockets[0])))->toBe('waiting-for-provider');
        if ($kind === 'assessment and assistance') {
            $secondSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($secondSockets === false || ($secondPid = pcntl_fork()) === -1) {
                throw new RuntimeException('Cannot start the simultaneous assistant.');
            }
            if ($secondPid === 0) {
                fclose($sockets[0]);
                fclose($secondSockets[0]);
                try {
                    DB::purge();
                    $waiting = false;
                    Http::fake(function () use (&$waiting, $secondSockets) {
                        if (! $waiting) {
                            $waiting = true;
                            fwrite($secondSockets[1], "waiting-for-provider\n");
                            stream_set_timeout($secondSockets[1], 15);
                            if (fgets($secondSockets[1]) === false) {
                                throw new RuntimeException('Editor did not finish while both checks were running.');
                            }
                        }

                        return Http::response(['data' => [], 'result' => []]);
                    });
                    (new DiscoverRelationsJob((string) Str::uuid()))->handle(app(RelationDiscoveryService::class));
                    fwrite($secondSockets[1], "completed\n");
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($secondSockets[1], 'error: '.$exception->getMessage()."\n");
                    exit(1);
                }
            }
            fclose($secondSockets[1]);
            stream_set_timeout($secondSockets[0], 15);
            expect(trim((string) fgets($secondSockets[0])))->toBe('waiting-for-provider');
        }
        DB::purge();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
        $started = microtime(true);
        $this->actingAs($actor)->postJson(route('editor.resources.store-draft'), [
            'resourceId' => $resource->id, 'intent' => 'autosave',
            'titles' => [['title' => 'Saved while checks are running', 'titleType' => 'main-title']],
        ])->assertOk();
        expect(microtime(true) - $started)->toBeLessThan(5.0);
        Http::fake(['api.test.datacite.org/*' => Http::response(['data' => ['id' => $resource->doi]], 200)]);
        $this->postJson(route('resources.register-doi', $resource), ['prefix' => ''])
            ->assertOk()->assertJson(['success' => true, 'updated' => true]);
        fwrite($sockets[0], "resume-provider\n");
        if (is_array($secondSockets)) {
            fwrite($secondSockets[0], "resume-provider\n");
            expect(trim((string) fgets($secondSockets[0])))->toBe('completed');
            pcntl_waitpid($secondPid, $secondStatus);
            expect(pcntl_wexitstatus($secondStatus))->toBe(0);
        }
        expect(trim((string) fgets($sockets[0])))->toBe('completed');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and($resource->fresh()->titles->first()->value)->toBe('Saved while checks are running');
    } finally {
        if (is_array($secondSockets)) {
            fclose($secondSockets[0]);
        }
        if (is_int($secondPid) && $secondPid > 0 && pcntl_waitpid($secondPid, $secondStatus, WNOHANG) === 0) {
            posix_kill($secondPid, SIGTERM);
            pcntl_waitpid($secondPid, $secondStatus);
        }
        fclose($sockets[0]);
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $run?->delete();
        $resource->delete();
        $actor->delete();
        DB::purge();
        DB::beginTransaction();
    }
})->with(['assessment', 'publication refresh', 'assistance', 'assessment and assistance'])
    ->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires pinned MySQL 9.7 and independent worker connections.');
