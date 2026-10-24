<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceCurationState;
use App\Models\User;
use App\Services\Curation\CurationCollaborationService;
use App\Services\Curation\CurationNotificationService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('mysql-sensitive');

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the MySQL 9.7 test server.');
    }
});

it('serializes concurrent curation writes on the resource row', function (string $operation): void {
    Queue::fake();
    Mail::fake();
    $actor = User::factory()->curator()->create();
    $next = User::factory()->curator()->create();
    $resource = Resource::factory()->create(['doi' => null]);
    if ($operation !== 'start') {
        app(CurationService::class)->start($resource, $actor);
    }
    DB::commit();
    DB::disconnect();
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Could not start concurrent curation writer.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                DB::purge();
                stream_set_timeout($pair[1], 20);
                fgets($pair[1]);
                try {
                    $service = app(CurationService::class);
                    match ($operation) {
                        'start' => $service->start($resource, $actor),
                        'lead' => app(CurationCollaborationService::class)->assign($resource, $actor, [], $next->id, 0, 'Handover', true),
                        default => $service->checklist($resource, $actor, 'data_received', 'done', null, 0),
                    };
                    fwrite($pair[1], "200\n");
                    exit(0);
                } catch (HttpException $exception) {
                    fwrite($pair[1], $exception->getStatusCode()."\n");
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($pair[1], 'error: '.$exception->getMessage()."\n");
                    exit(1);
                }
            }
            fclose($pair[1]);
            $workers[] = [$pid, $pair[0]];
        }
        foreach ($workers as [, $socket]) {
            stream_set_timeout($socket, 30);
            fwrite($socket, "go\n");
        }
        $statuses = [];
        foreach ($workers as [$pid, $socket]) {
            $statuses[] = trim((string) fgets($socket));
            pcntl_waitpid($pid, $exit);
            expect(pcntl_wexitstatus($exit))->toBe(0);
        }
        sort($statuses);
        expect($statuses)->toBe($operation === 'start' ? ['200', '200'] : ['200', '409']);
        DB::purge();
        expect(ResourceCurationState::whereKey($resource->id)->count())->toBe(1);
        expect(ResourceCurationEvent::where('resource_id', $resource->id)->where('type', match ($operation) {
            'start' => 'curation.started', 'lead' => 'curation.handover', default => 'checklist.changed',
        })->count())->toBe(1);
    } finally {
        foreach ($workers as [$pid, $socket]) {
            fclose($socket);
            pcntl_waitpid($pid, $exit, WNOHANG);
        }
        DB::purge();
        Resource::whereKey($resource->id)->delete();
        User::whereIn('id', [$actor->id, $next->id])->delete();
        DB::beginTransaction();
    }
})->with(['start', 'lead', 'checklist']);

it('rolls back actual queued notification jobs together with their receipt', function (): void {
    $actor = User::factory()->curator()->create();
    $recipient = User::factory()->curator()->create();
    $resource = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($resource, $actor);
    DB::beginTransaction();
    app(CurationCollaborationService::class)->assign($resource, $actor, [], $recipient->id, 0, null, true);
    app(CurationNotificationService::class)->dispatch();
    expect(DB::table('jobs')->count())->toBeGreaterThan(0);
    DB::rollBack();
    expect(DB::table('resource_curation_notifications')->count())->toBe(0)
        ->and(ResourceCurationEvent::where('type', 'curation.handover')->count())->toBe(0)
        ->and(DB::table('jobs')->where('payload', 'like', '%CurationNotification%')->count())->toBe(0);
});

it('supports additive migration rollback and historical projection rebuilds', function (): void {
    $resource = Resource::factory()->create();
    $migration = require database_path('migrations/2026_10_21_000001_create_resource_curation_tables.php');
    $migration->down();
    try {
        expect(Schema::hasTable('resource_curation_states'))->toBeFalse();
        app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);
        expect(DB::table('resource_listing_projections')->where('resource_id', $resource->id)->exists())->toBeTrue();
    } finally {
        $migration->up();
    }
    expect(Schema::hasColumn('resource_listing_projections', 'curation_started'))->toBeTrue();
    expect(Schema::hasIndex('resource_curators', ['resource_id', 'user_id']))->toBeTrue();
    Resource::whereKey($resource->id)->delete();
});
