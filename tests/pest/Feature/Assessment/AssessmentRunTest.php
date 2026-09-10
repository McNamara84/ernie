<?php

declare(strict_types=1);

use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Jobs\AssessResourceRunItemJob;
use App\Jobs\DispatchAssessmentRunItemsJob;
use App\Jobs\PrepareAssessmentRunSnapshotJob;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Assessment\AssessmentQueueService;
use App\Services\Assessment\AssessmentRunService;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config([
        'fuji.enabled' => true,
        'fuji.base_url' => 'https://fuji.test',
        'fuji.username' => 'admin',
        'fuji.password' => 'secret',
        'fuji.metric_version' => 'metrics_v0.8',
        'fuji.use_datacite' => true,
        'fuji.use_github' => false,
        'fuji.assessment.queue_connection' => 'assessment',
        'fuji.assessment.queue' => 'assessments',
        'fuji.assessment.concurrency' => 2,
        'fuji.assessment.requests_per_minute' => 1000,
        'fuji.assessment.minimum_interval_ms' => 0,
        'fuji.assessment.max_attempts' => 3,
        'fuji.assessment.retry_base_seconds' => 1,
        'fuji.assessment.retry_jitter_seconds' => 0,
        'queue.connections.assessment.driver' => 'database',
    ]);

    Cache::flush();
    Queue::fake();
});

function assessmentPhysicalObjectType(): ResourceType
{
    return ResourceType::query()->firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
}

/** @return array{0: AssessmentRun, 1: AssessmentRunItem} */
function queuedAssessmentItem(Resource $resource, AssessmentScope $scope = AssessmentScope::RESOURCE): array
{
    $run = AssessmentRun::factory()->create([
        'scope' => $scope,
        'active_scope' => $scope,
        'status' => AssessmentRunStatus::RUNNING,
        'total' => 1,
        'pending' => 1,
        'processed' => 0,
        'assessed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'started_at' => now(),
    ]);
    $item = AssessmentRunItem::factory()->for($run, 'run')->for($resource)->create([
        'identifier' => $resource->doi,
        'status' => AssessmentRunItemStatus::QUEUED,
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    return [$run, $item];
}

function handleAssessmentItem(AssessResourceRunItemJob $job): void
{
    $job->handle(
        app(FujiAssessmentService::class),
        app(FujiAssessmentRequestLimiterService::class),
        app(ResourceCacheService::class),
        app(AssessmentRunService::class),
    );
}

function prepareAssessmentRun(AssessmentRun $run): void
{
    (new PrepareAssessmentRunSnapshotJob($run->id))->handle(app(AssessmentRunService::class));
}

function successfulFujiAssessment(float $score = 73.08): array
{
    return [
        'summary' => ['score_percent' => ['FAIR' => $score]],
        'results' => [],
    ];
}

test('a resource run persists a complete scope snapshot and skips missing dois', function (): void {
    $physicalType = assessmentPhysicalObjectType();
    $resource = Resource::factory()->withDoi('10.5880/assessment.snapshot')->create();
    $withoutDoi = Resource::factory()->create(['doi' => null]);
    $igsn = Resource::factory()->withDoi('10.60510/IGSN.SNAPSHOT')->create(['resource_type_id' => $physicalType->id]);
    Cache::flush();

    $run = app(AssessmentRunService::class)->startOrResume(AssessmentScope::RESOURCE, User::factory()->admin()->create());

    expect($run->scope)->toBe(AssessmentScope::RESOURCE)
        ->and($run->status)->toBe(AssessmentRunStatus::PREPARING)
        ->and($run->total)->toBe(0)
        ->and($run->items()->exists())->toBeFalse()
        ->and(ResourceAssessment::query()->where('resource_id', $withoutDoi->id)->exists())->toBeFalse();
    Queue::assertPushedOn('assessments', PrepareAssessmentRunSnapshotJob::class);

    prepareAssessmentRun($run);
    $run->refresh();

    expect($run->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($run->total)->toBe(2)
        ->and($run->processed)->toBe(1)
        ->and($run->skipped)->toBe(1)
        ->and($run->pending)->toBe(1)
        ->and($run->items()->pluck('resource_id')->all())->toContain($resource->id, $withoutDoi->id)
        ->and($run->items()->where('resource_id', $igsn->id)->exists())->toBeFalse();

    expect(ResourceAssessment::query()->where('resource_id', $withoutDoi->id)->value('status'))
        ->toBe(ResourceAssessment::STATUS_SKIPPED);
    Queue::assertPushedOn('assessments', DispatchAssessmentRunItemsJob::class);
});

test('an IGSN run snapshots only physical-object resources', function (): void {
    $physicalType = assessmentPhysicalObjectType();
    $igsn = Resource::factory()->withDoi('10.60510/ASSESSMENT.IGSN')->create([
        'resource_type_id' => $physicalType->id,
    ]);
    $resource = Resource::factory()->withDoi('10.5880/assessment.not-igsn')->create();
    Cache::flush();

    $run = app(AssessmentRunService::class)->startOrResume(AssessmentScope::IGSN, User::factory()->admin()->create());

    prepareAssessmentRun($run);
    $run->refresh();

    expect($run->total)->toBe(1)
        ->and($run->items()->where('resource_id', $igsn->id)->exists())->toBeTrue()
        ->and($run->items()->where('resource_id', $resource->id)->exists())->toBeFalse();
});

test('a persistent queue driver is required before a run can be created', function (): void {
    config(['queue.connections.assessment.driver' => 'sync']);

    expect(fn () => app(AssessmentRunService::class)->startOrResume(
        AssessmentScope::RESOURCE,
        User::factory()->admin()->create(),
    ))->toThrow(ValidationException::class, 'persistent queue connection');

    expect(AssessmentRun::query()->count())->toBe(0);
});

test('starting an active run is idempotent and returns the same snapshot', function (): void {
    Resource::factory()->withDoi('10.5880/assessment.active')->create();
    $user = User::factory()->admin()->create();
    $service = app(AssessmentRunService::class);

    $first = $service->startOrResume(AssessmentScope::RESOURCE, $user);
    Resource::factory()->withDoi('10.5880/assessment.later')->create();
    $second = $service->startOrResume(AssessmentScope::RESOURCE, $user);

    prepareAssessmentRun($first);
    $first->refresh();

    expect($second->id)->toBe($first->id)
        ->and($first->total)->toBe(1)
        ->and($first->items()->where('identifier', '10.5880/assessment.later')->exists())->toBeFalse()
        ->and(AssessmentRun::query()->count())->toBe(1);
    Queue::assertPushed(PrepareAssessmentRunSnapshotJob::class, 1);
});

test('a paused run resets open leases and resumes the same run', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.resume')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    $run->update([
        'status' => AssessmentRunStatus::PAUSED,
        'pause_reason' => 'Worker stopped.',
        'paused_at' => now(),
    ]);
    $item->update([
        'status' => AssessmentRunItemStatus::PROCESSING,
        'processing_started_at' => now()->subMinute(),
    ]);

    $resumed = app(AssessmentRunService::class)->startOrResume(AssessmentScope::RESOURCE, User::factory()->admin()->create());

    expect($resumed->id)->toBe($run->id)
        ->and($resumed->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($resumed->pause_reason)->toBeNull()
        ->and($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($item->fresh()->processing_started_at)->toBeNull();
});

test('a completed run allows a new complete snapshot', function (): void {
    Resource::factory()->withDoi('10.5880/assessment.complete')->create();
    $old = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::COMPLETED,
        'active_scope' => null,
        'total' => 0,
        'pending' => 0,
        'completed_at' => now(),
    ]);

    $new = app(AssessmentRunService::class)->startOrResume(AssessmentScope::RESOURCE, User::factory()->admin()->create());

    expect($new->id)->not->toBe($old->id)
        ->and(AssessmentRun::query()->count())->toBe(2)
        ->and($new->status)->toBe(AssessmentRunStatus::PREPARING)
        ->and($new->total)->toBe(0);

    prepareAssessmentRun($new);

    expect($new->fresh()->total)->toBe(1)
        ->and($new->fresh()->status)->toBe(AssessmentRunStatus::QUEUED);
});

test('snapshot preparation resumes from its persisted cursor in bounded chunks', function (): void {
    config(['fuji.assessment.snapshot_chunk_size' => 2]);
    $resources = collect([
        Resource::factory()->withDoi('10.5880/assessment.chunk.1')->create(),
        Resource::factory()->withDoi('10.5880/assessment.chunk.2')->create(),
        Resource::factory()->withDoi('10.5880/assessment.chunk.3')->create(),
    ]);
    $run = app(AssessmentRunService::class)->startOrResume(AssessmentScope::RESOURCE, User::factory()->admin()->create());

    prepareAssessmentRun($run);

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::PREPARING)
        ->and($run->fresh()->preparation_cursor)->toBe($resources[1]->id)
        ->and($run->items()->count())->toBe(2);

    prepareAssessmentRun($run);

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($run->fresh()->prepared_at)->not->toBeNull()
        ->and($run->fresh()->preparation_cursor)->toBe($resources[2]->id)
        ->and($run->items()->count())->toBe(3);
});

test('a failed snapshot preparation remains resumable from the same run', function (): void {
    Resource::factory()->withDoi('10.5880/assessment.prepare-resume')->create();
    $user = User::factory()->admin()->create();
    $run = app(AssessmentRunService::class)->startOrResume(AssessmentScope::RESOURCE, $user);

    (new PrepareAssessmentRunSnapshotJob($run->id))->failed(new RuntimeException('Database connection lost.'));

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::PAUSED)
        ->and($run->fresh()->active_scope)->toBe(AssessmentScope::RESOURCE)
        ->and($run->fresh()->preparation_cursor)->toBe(0);

    $resumed = app(AssessmentRunService::class)->resume($run->fresh(), $user);
    expect($resumed->status)->toBe(AssessmentRunStatus::PREPARING);

    prepareAssessmentRun($resumed);

    expect($resumed->fresh()->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($resumed->items()->count())->toBe(1);
});

test('the dispatcher fills only the configured execution window', function (): void {
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::QUEUED,
        'concurrency' => 2,
        'total' => 3,
        'pending' => 3,
    ]);
    AssessmentRunItem::factory()->count(3)->for($run, 'run')->create();

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::RUNNING)
        ->and($run->items()->where('status', AssessmentRunItemStatus::QUEUED)->count())->toBe(2)
        ->and($run->items()->where('status', AssessmentRunItemStatus::PENDING)->count())->toBe(1);
    Queue::assertPushed(AssessResourceRunItemJob::class, 2);
    Queue::assertPushedOn('assessments', AssessResourceRunItemJob::class);
});

test('the dispatcher never completes a run whose snapshot is still being prepared', function (): void {
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::PREPARING,
        'prepared_at' => null,
        'total' => 0,
        'pending' => 0,
    ]);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::PREPARING)
        ->and($run->fresh()->active_scope)->toBe(AssessmentScope::RESOURCE);
    Queue::assertNotPushed(AssessResourceRunItemJob::class);
});

test('duplicate dispatcher jobs do not exceed the configured execution window', function (): void {
    config(['fuji.assessment.concurrency' => 1]);
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::RUNNING,
        'concurrency' => 1,
        'total' => 2,
        'pending' => 2,
        'started_at' => now(),
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->create([
        'status' => AssessmentRunItemStatus::QUEUED,
        'lease_expires_at' => now()->addMinutes(5),
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->create([
        'status' => AssessmentRunItemStatus::PENDING,
    ]);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->items()->where('status', AssessmentRunItemStatus::QUEUED)->count())->toBe(1)
        ->and($run->items()->where('status', AssessmentRunItemStatus::PENDING)->count())->toBe(1);
    Queue::assertNotPushed(AssessResourceRunItemJob::class);
});

test('the dispatcher recovers an expired item lease', function (): void {
    config(['fuji.assessment.concurrency' => 1]);
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::RUNNING,
        'concurrency' => 1,
        'total' => 1,
        'pending' => 1,
        'started_at' => now()->subMinute(),
    ]);
    $item = AssessmentRunItem::factory()->for($run, 'run')->create([
        'status' => AssessmentRunItemStatus::PROCESSING,
        'processing_started_at' => now()->subMinutes(5),
        'lease_expires_at' => now()->subMinute(),
    ]);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::QUEUED)
        ->and($item->fresh()->processing_started_at)->toBeNull()
        ->and($item->fresh()->lease_expires_at?->isFuture())->toBeTrue();
    Queue::assertPushed(AssessResourceRunItemJob::class, 1);
});

test('the dispatcher completes a run only after every item is terminal', function (): void {
    Log::spy();
    $run = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::IGSN,
        'active_scope' => AssessmentScope::IGSN,
        'status' => AssessmentRunStatus::RUNNING,
        'total' => 1,
        'processed' => 1,
        'assessed' => 1,
        'pending' => 0,
        'started_at' => now()->subMinute(),
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->create([
        'status' => AssessmentRunItemStatus::ASSESSED,
        'processed_at' => now(),
    ]);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::COMPLETED)
        ->and($run->fresh()->active_scope)->toBeNull()
        ->and($run->fresh()->completed_at)->not->toBeNull();
    Queue::assertNotPushed(AssessResourceRunItemJob::class);
    Log::shouldHaveReceived('info')->once()->with(
        'IGSN assessment run completed',
        Mockery::on(fn (array $context): bool => $context['scope'] === AssessmentScope::IGSN->value),
    );
});

test('the dispatcher cancels every open item after cancellation is requested', function (): void {
    Log::spy();
    $run = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::IGSN,
        'active_scope' => AssessmentScope::IGSN,
        'status' => AssessmentRunStatus::CANCEL_REQUESTED,
        'total' => 2,
        'pending' => 2,
    ]);
    AssessmentRunItem::factory()->count(2)->for($run, 'run')->create();

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::CANCELLED)
        ->and($run->fresh()->active_scope)->toBeNull()
        ->and($run->fresh()->processed)->toBe(2)
        ->and($run->fresh()->skipped)->toBe(2)
        ->and($run->items()->where('status', AssessmentRunItemStatus::CANCELLED)->count())->toBe(2);
    Log::shouldHaveReceived('info')->once()->with(
        'IGSN assessment run cancelled',
        Mockery::on(fn (array $context): bool => $context['scope'] === AssessmentScope::IGSN->value),
    );
});

test('the dispatcher pauses a run when its snapshotted F-UJI configuration changed', function (): void {
    Log::spy();
    $user = User::factory()->admin()->create();
    $run = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::IGSN,
        'active_scope' => AssessmentScope::IGSN,
        'status' => AssessmentRunStatus::QUEUED,
        'fuji_base_url' => 'https://old-fuji.test',
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->create();

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::PAUSED)
        ->and($run->fresh()->pause_reason)->toContain('configuration changed');
    Queue::assertNotPushed(AssessResourceRunItemJob::class);
    Log::shouldHaveReceived('warning')->once()->with(
        'IGSN assessment run paused',
        Mockery::on(fn (array $context): bool => $context['scope'] === AssessmentScope::IGSN->value),
    );

    $resumed = app(AssessmentRunService::class)->resume($run->fresh(), $user);

    expect($resumed->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($resumed->fuji_base_url)->toBe('https://fuji.test')
        ->and($resumed->metric_version)->toBe('metrics_v0.8')
        ->and($resumed->concurrency)->toBe(2)
        ->and($resumed->requests_per_minute)->toBe(1000)
        ->and($resumed->active_scope)->toBe(AssessmentScope::IGSN);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::RUNNING);
    Queue::assertPushed(AssessResourceRunItemJob::class, 1);
});

test('the dispatcher pauses a run when a snapshotted throughput setting changed', function (
    string $configurationKey,
    string $runAttribute,
    int $snapshottedValue,
    int $configuredValue,
): void {
    $user = User::factory()->admin()->create();
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::QUEUED,
        $runAttribute => $snapshottedValue,
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->create();
    config([$configurationKey => $configuredValue]);

    (new DispatchAssessmentRunItemsJob($run->id))->handle(
        app(AssessmentRunService::class),
        app(AssessmentQueueService::class),
    );

    expect($run->fresh()->status)->toBe(AssessmentRunStatus::PAUSED)
        ->and($run->fresh()->pause_reason)->toContain('configuration changed');
    Queue::assertNotPushed(AssessResourceRunItemJob::class);

    $resumed = app(AssessmentRunService::class)->resume($run->fresh(), $user);

    expect($resumed->status)->toBe(AssessmentRunStatus::QUEUED)
        ->and($resumed->{$runAttribute})->toBe($configuredValue);
    Queue::assertPushed(DispatchAssessmentRunItemsJob::class, 1);
})->with([
    'lower concurrency' => ['fuji.assessment.concurrency', 'concurrency', 2, 1],
    'lower request rate' => ['fuji.assessment.requests_per_minute', 'requests_per_minute', 1000, 100],
]);

test('cancelling a paused run releases its scope and terminalizes open items', function (): void {
    Log::spy();
    $user = User::factory()->admin()->create();
    $run = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::IGSN,
        'active_scope' => AssessmentScope::IGSN,
        'status' => AssessmentRunStatus::PAUSED,
        'total' => 2,
        'pending' => 2,
        'pause_reason' => 'Configuration changed.',
        'paused_at' => now(),
    ]);
    AssessmentRunItem::factory()->count(2)->for($run, 'run')->create();

    $cancelled = app(AssessmentRunService::class)->cancel($run, $user);

    expect($cancelled->status)->toBe(AssessmentRunStatus::CANCELLED)
        ->and($cancelled->active_scope)->toBeNull()
        ->and($cancelled->last_controlled_by_user_id)->toBe($user->id)
        ->and($cancelled->pending)->toBe(0)
        ->and($cancelled->skipped)->toBe(2)
        ->and($cancelled->items()->where('status', AssessmentRunItemStatus::CANCELLED)->count())->toBe(2);
    Log::shouldHaveReceived('info')->once()->with(
        'IGSN assessment run cancelled',
        Mockery::on(fn (array $context): bool => $context['scope'] === AssessmentScope::IGSN->value),
    );

    $replacement = app(AssessmentRunService::class)->startOrResume(AssessmentScope::IGSN, $user);

    expect($replacement->id)->not->toBe($run->id)
        ->and($replacement->status)->toBe(AssessmentRunStatus::PREPARING);
});

test('an item job stores a successful assessment atomically', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.success')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(successfulFujiAssessment())]);

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->firstOrFail();
    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::ASSESSED)
        ->and($item->fresh()->attempts)->toBe(1)
        ->and($item->fresh()->last_http_status)->toBe(200)
        ->and($assessment->status)->toBe(ResourceAssessment::STATUS_COMPLETED)
        ->and($assessment->total_score)->toBe('73.08')
        ->and($run->fresh()->processed)->toBe(1)
        ->and($run->fresh()->assessed)->toBe(1)
        ->and($run->fresh()->pending)->toBe(0);
    Queue::assertPushed(DispatchAssessmentRunItemsJob::class);
});

test('duplicate item delivery does not assess a terminal item twice', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.idempotent')->create();
    [, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(successfulFujiAssessment())]);
    $job = new AssessResourceRunItemJob($item->id);

    handleAssessmentItem($job);
    handleAssessmentItem($job);

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::ASSESSED)
        ->and($item->fresh()->attempts)->toBe(1);
    Http::assertSentCount(1);
});

test('an assessment result is discarded and retried when the DOI changes in flight', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.old-doi')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    Http::fake(function () use ($resource) {
        $resource->update(['doi' => '10.5880/assessment.new-doi']);

        return Http::response(successfulFujiAssessment());
    });

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($item->fresh()->identifier)->toBe('10.5880/assessment.new-doi')
        ->and($item->fresh()->attempts)->toBe(1)
        ->and($run->fresh()->processed)->toBe(0)
        ->and($run->fresh()->pending)->toBe(1)
        ->and(ResourceAssessment::query()->where('resource_id', $resource->id)->exists())->toBeFalse();
});

test('a resource deleted during assessment is skipped without storing the stale result', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.deleted')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    Http::fake(function () use ($resource) {
        $resource->delete();

        return Http::response(successfulFujiAssessment());
    });

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::SKIPPED)
        ->and($item->fresh()->resource_id)->toBeNull()
        ->and($item->fresh()->identifier)->toBe('10.5880/assessment.deleted')
        ->and($run->fresh()->processed)->toBe(1)
        ->and($run->fresh()->skipped)->toBe(1)
        ->and(ResourceAssessment::query()->where('resource_id', $resource->id)->exists())->toBeFalse();
});

test('a transient F-UJI failure is deferred without losing the item', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.retry')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(['error' => 'Unavailable'], 500)]);

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($item->fresh()->attempts)->toBe(1)
        ->and($item->fresh()->last_http_status)->toBe(500)
        ->and($item->fresh()->available_at?->isFuture())->toBeTrue()
        ->and($run->fresh()->processed)->toBe(0)
        ->and(ResourceAssessment::query()->where('resource_id', $resource->id)->exists())->toBeFalse();
});

test('a transient F-UJI failure locks the run before moving its item back to pending', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.retry-lock-order')->create();
    [, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(['error' => 'Unavailable'], 500)]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        handleAssessmentItem(new AssessResourceRunItemJob($item->id));
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
    } finally {
        DB::disableQueryLog();
    }
    $deferUpdateIndex = $queries->search(
        fn (string $query): bool => str_contains($query, 'assessment_run_items')
            && str_contains($query, 'available_at'),
    );
    if (! is_int($deferUpdateIndex)) {
        throw new RuntimeException('The deferred assessment item update was not recorded.');
    }

    $runLockQuery = $queries->get($deferUpdateIndex - 1);

    expect($runLockQuery)->toBeString()
        ->and($runLockQuery)->toContain('assessment_runs')
        ->and($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect(strtolower($runLockQuery))->toContain('for update');
    }
});

test('a rate-limit response honors Retry-After and imposes a shared cooldown', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.rate-limit')->create();
    [, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(['error' => 'Too many requests'], 429, ['Retry-After' => '30'])]);

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($item->fresh()->attempts)->toBe(1)
        ->and($item->fresh()->last_http_status)->toBe(429)
        ->and($item->fresh()->available_at?->greaterThanOrEqualTo(now()->addSeconds(28)))->toBeTrue()
        ->and(app(FujiAssessmentRequestLimiterService::class)->reserveSlot())->toBeGreaterThanOrEqual(29_000);
});

test('a permanent F-UJI failure becomes a terminal resource failure', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.bad-request')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    Http::fake(['https://fuji.test/*' => Http::response(['error' => 'Bad request'], 400)]);

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::FAILED)
        ->and($item->fresh()->attempts)->toBe(1)
        ->and($run->fresh()->failed)->toBe(1)
        ->and($run->fresh()->pending)->toBe(0)
        ->and(ResourceAssessment::query()->where('resource_id', $resource->id)->value('status'))
        ->toBe(ResourceAssessment::STATUS_FAILED);
});

test('a transient failure becomes terminal after the configured attempt limit', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.exhausted')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    $item->update(['attempts' => 2]);
    Http::fake(['https://fuji.test/*' => Http::response(['error' => 'Unavailable'], 503)]);

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::FAILED)
        ->and($item->fresh()->attempts)->toBe(3)
        ->and($run->fresh()->failed)->toBe(1);
});

test('a saturated limiter defers an item without counting an assessment attempt', function (): void {
    config([
        'fuji.assessment.requests_per_minute' => 1,
        'fuji.assessment.window_seconds' => 300,
    ]);
    $resource = Resource::factory()->withDoi('10.5880/assessment.limited')->create();
    [, $item] = queuedAssessmentItem($resource);
    expect(app(FujiAssessmentRequestLimiterService::class)->reserveSlot())->toBe(0);
    Http::fake();

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($item->fresh()->attempts)->toBe(0)
        ->and($item->fresh()->available_at?->isFuture())->toBeTrue();
    Http::assertNothingSent();
});

test('a worker failure pauses the run and makes its item resumable', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.worker-failure')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    $item->update(['status' => AssessmentRunItemStatus::PROCESSING]);

    (new AssessResourceRunItemJob($item->id))->failed(new RuntimeException('Worker terminated.'));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::PENDING)
        ->and($run->fresh()->status)->toBe(AssessmentRunStatus::PAUSED)
        ->and($run->fresh()->pause_reason)->toContain('worker failed')
        ->and($run->fresh()->last_error)->toBe('Worker terminated.');
});

test('a resource that moved out of scope is skipped without calling F-UJI', function (): void {
    $physicalType = assessmentPhysicalObjectType();
    $resource = Resource::factory()->withDoi('10.5880/assessment.scope')->create();
    [$run, $item] = queuedAssessmentItem($resource);
    $resource->update(['resource_type_id' => $physicalType->id]);
    Cache::flush();
    Http::fake();

    handleAssessmentItem(new AssessResourceRunItemJob($item->id));

    expect($item->fresh()->status)->toBe(AssessmentRunItemStatus::SKIPPED)
        ->and($run->fresh()->skipped)->toBe(1);
    Http::assertNothingSent();
});
