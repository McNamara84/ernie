<?php

declare(strict_types=1);

use App\Enums\IgsnRegistrationItemStatus;
use App\Enums\IgsnRegistrationRunStatus;
use App\Jobs\ProcessIgsnRegistrationRunJob;
use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteModeResolverService;
use App\Services\DataCiteRegistrationFactoryService;
use App\Services\IgsnRegistrationExclusionService;
use App\Services\IgsnRegistrationRunService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'queue.default' => 'database',
        'datacite.queue' => 'datacite',
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
        'datacite.production.username' => 'PROD.USER',
        'datacite.production.password' => 'prod-password',
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.prefixes' => ['10.5880'],
        'datacite.production.igsn_prefix' => '10.60510',
        'datacite.production.igsn_username' => 'GFZ.IGSN',
        'datacite.production.igsn_password' => 'igsn-password',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 10000,
    ]);

    Cache::flush();
    Queue::fake();
    $this->curator = User::factory()->curator()->create();
});

/**
 * @param  array<string, mixed>  $resourceOverrides
 * @param  array<string, mixed>  $metadataOverrides
 */
function createQueuedIgsn(array $resourceOverrides = [], array $metadataOverrides = [], bool $withLandingPage = true): Resource
{
    $resource = Resource::factory()->create(array_merge([
        'doi' => '10.83279/'.strtoupper(fake()->unique()->bothify('QUEUE-####??')),
        'publication_year' => 2020,
    ], $resourceOverrides));

    IgsnMetadata::create(array_merge([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_UPLOADED,
        'sample_type' => 'Rock',
        'material' => 'Granite',
    ], $metadataOverrides));

    if ($withLandingPage) {
        LandingPage::factory()->create(['resource_id' => $resource->id]);
    }

    return $resource->fresh(['igsnMetadata', 'landingPage']);
}

/** @return Collection<int, Resource> */
function createQueuedIgsnsInBulk(int $count): Collection
{
    $now = now();
    $resourceRows = [];

    foreach (range(1, $count) as $index) {
        $resourceRows[] = [
            'doi' => sprintf('10.83279/BULK-REGISTER-%04d', $index),
            'identifier_type' => 'DOI',
            'publication_year' => 2020,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    Resource::query()->insert($resourceRows);
    $resources = Resource::query()
        ->where('doi', 'like', '10.83279/BULK-REGISTER-%')
        ->orderBy('id')
        ->get();

    IgsnMetadata::query()->insert($resources->map(fn (Resource $resource): array => [
        'resource_id' => $resource->id,
        'sample_type' => 'Rock',
        'material' => 'Granite',
        'upload_status' => IgsnMetadata::STATUS_UPLOADED,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());
    LandingPage::query()->insert($resources->map(fn (Resource $resource): array => [
        'resource_id' => $resource->id,
        'doi_prefix' => null,
        'slug' => 'bulk-register-'.$resource->id,
        'template' => 'default_gfz',
        'is_published' => true,
        'published_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());

    return $resources;
}

function runQueuedIgsnRegistrationStep(string $runId): void
{
    (new ProcessIgsnRegistrationRunJob($runId))->handle(
        app(DataCiteRegistrationFactoryService::class),
        app(DataCiteModeResolverService::class),
        app(IgsnRegistrationRunService::class),
        app(IgsnRegistrationExclusionService::class),
    );
}

test('batch registration creates a persistent run and queues it', function (): void {
    $first = createQueuedIgsn(['doi' => '10.83279/QUEUE-FIRST']);
    $second = createQueuedIgsn(
        ['doi' => '10.83279/QUEUE-SECOND'],
        ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
    );

    $response = $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$first->id, $second->id]])
        ->assertAccepted()
        ->assertJsonPath('run.status', IgsnRegistrationRunStatus::QUEUED->value)
        ->assertJsonPath('run.total', 2)
        ->assertJsonPath('run.test_mode', true);

    $runId = $response->json('run.id');
    expect($runId)->toBeString();
    $this->assertDatabaseHas('igsn_registration_runs', [
        'id' => $runId,
        'initiated_by_user_id' => $this->curator->id,
        'total' => 2,
    ]);
    $this->assertDatabaseHas('igsn_registration_items', [
        'run_id' => $runId,
        'resource_id' => $first->id,
        'operation' => 'register',
    ]);
    $this->assertDatabaseHas('igsn_registration_items', [
        'run_id' => $runId,
        'resource_id' => $second->id,
        'operation' => 'update',
    ]);
    Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
    Http::assertNothingSent();
});

test('batch registration accepts and persists exactly 1000 ordered items', function (): void {
    $resources = createQueuedIgsnsInBulk(1000);
    $ids = $resources->pluck('id')->all();

    $response = $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => $ids])
        ->assertAccepted()
        ->assertJsonPath('run.total', 1000)
        ->assertJsonPath('run.status', IgsnRegistrationRunStatus::QUEUED->value);

    $run = IgsnRegistrationRun::query()->findOrFail($response->json('run.id'));
    expect($run->items()->count())->toBe(1000)
        ->and($run->items()->orderBy('id')->firstOrFail()->resource_id)->toBe($ids[0])
        ->and($run->items()->orderByDesc('id')->firstOrFail()->resource_id)->toBe($ids[999]);
    Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
    Http::assertNothingSent();
});

test('batch registration validates boundary, duplicates, resources, and landing pages', function (): void {
    $resource = createQueuedIgsn();
    $withoutLandingPage = createQueuedIgsn(withLandingPage: false);
    $regularResource = Resource::factory()->create();

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => range(1, 1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id, $resource->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [999999]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$regularResource->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$withoutLandingPage->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');

    expect(IgsnRegistrationRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('batch registration requires a persistent queue connection', function (): void {
    config(['queue.default' => 'sync']);
    $resource = createQueuedIgsn();

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('queue');

    expect(IgsnRegistrationRun::query()->count())->toBe(0);
});

test('beginner run snapshots test mode when production mode is configured', function (): void {
    config(['datacite.test_mode' => false]);
    $beginner = User::factory()->beginner()->create();
    $resource = createQueuedIgsn();

    $this->actingAs($beginner)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
        ->assertAccepted()
        ->assertJsonPath('run.test_mode', true)
        ->assertJsonPath('run.datacite_endpoint', 'https://api.test.datacite.org');

    $run = IgsnRegistrationRun::query()->firstOrFail();
    expect($run->test_mode)->toBeTrue()
        ->and($run->datacite_endpoint)->toBe('https://api.test.datacite.org');
});

test('active runs cannot overlap on the same IGSN', function (): void {
    $resource = createQueuedIgsn();

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
        ->assertAccepted();

    $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');

    expect(IgsnRegistrationRun::query()->count())->toBe(1);
});

test('batch registration does not queue a resource while its single-registration lock is held', function (): void {
    $resource = createQueuedIgsn();
    $lock = app(IgsnRegistrationExclusionService::class)->resourceLock($resource->id);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($this->curator)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    } finally {
        $lock->release();
    }

    expect(IgsnRegistrationRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('IGSN list exposes the current users persistent registration run', function (): void {
    $resource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);

    $this->actingAs($this->curator)
        ->get('/igsns')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->where('igsnRegistrationRun.id', $run->id)
            ->where('igsnRegistrationRun.status', IgsnRegistrationRunStatus::QUEUED->value));
});

test('run endpoints enforce ownership while admins can inspect and control runs', function (): void {
    $resource = createQueuedIgsn();
    $response = $this->actingAs($this->curator)
        ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
        ->assertAccepted();
    $run = IgsnRegistrationRun::query()->findOrFail($response->json('run.id'));
    $otherCurator = User::factory()->curator()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($otherCurator)
        ->getJson(route('igsns.batch-register.show', $run))
        ->assertForbidden();

    $this->actingAs($this->curator)
        ->getJson(route('igsns.batch-register.show', $run))
        ->assertOk()
        ->assertJsonPath('run.id', $run->id);

    $this->actingAs($admin)
        ->postJson(route('igsns.batch-register.cancel', $run))
        ->assertOk()
        ->assertJsonPath('run.status', IgsnRegistrationRunStatus::CANCEL_REQUESTED->value);
});

test('issues endpoint is paginated and filters failed and cancelled items', function (): void {
    $run = IgsnRegistrationRun::factory()->for($this->curator, 'initiatedBy')->create([
        'status' => IgsnRegistrationRunStatus::COMPLETED,
        'total' => 3,
        'processed' => 3,
        'failed' => 1,
        'cancelled' => 1,
    ]);
    IgsnRegistrationItem::factory()->for($run, 'run')->create(['status' => IgsnRegistrationItemStatus::REGISTERED]);
    IgsnRegistrationItem::factory()->for($run, 'run')->create(['status' => IgsnRegistrationItemStatus::FAILED]);
    IgsnRegistrationItem::factory()->for($run, 'run')->create(['status' => IgsnRegistrationItemStatus::CANCELLED]);

    $this->actingAs($this->curator)
        ->getJson(route('igsns.batch-register.items', [$run, 'issues' => 1]))
        ->assertOk()
        ->assertJsonPath('pagination.total', 2)
        ->assertJsonCount(2, 'items');
});

test('job registers one item per invocation and completes the run', function (): void {
    $first = createQueuedIgsn(['doi' => '10.83279/JOB-FIRST', 'publication_year' => 2020]);
    $second = createQueuedIgsn(['doi' => '10.83279/JOB-SECOND', 'publication_year' => 2019]);
    $run = app(IgsnRegistrationRunService::class)->start([$first->id, $second->id], $this->curator);

    Http::fake(fn (Request $request) => Http::response([
        'data' => [
            'id' => $request->data()['data']['attributes']['doi'],
            'type' => 'dois',
            'attributes' => ['state' => 'findable'],
        ],
    ], 201));

    runQueuedIgsnRegistrationStep($run->id);
    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::RUNNING)
        ->and($run->processed)->toBe(1)
        ->and($run->registered)->toBe(1);
    expect($first->fresh()->publication_year)->toBe((int) date('Y'))
        ->and($second->fresh()->publication_year)->toBe(2019);

    runQueuedIgsnRegistrationStep($run->id);
    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::COMPLETED)
        ->and($run->processed)->toBe(2)
        ->and($run->registered)->toBe(2)
        ->and($run->failed)->toBe(0);
    expect($second->fresh()->publication_year)->toBe((int) date('Y'));
    Http::assertSentCount(2);
});

test('job locks out concurrent resource registration and defers an untouched item', function (): void {
    $resource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);
    Queue::fake();
    Http::fake();
    $lock = app(IgsnRegistrationExclusionService::class)->resourceLock($resource->id);
    expect($lock->get())->toBeTrue();

    try {
        runQueuedIgsnRegistrationStep($run->id);
    } finally {
        $lock->release();
    }

    $item = $run->items()->firstOrFail()->refresh();
    expect($run->fresh()->processed)->toBe(0)
        ->and($item->status)->toBe(IgsnRegistrationItemStatus::PENDING)
        ->and($item->attempts)->toBe(0)
        ->and(IgsnRegistrationExclusionService::LOCK_TTL_SECONDS)->toBeGreaterThan((new ProcessIgsnRegistrationRunJob($run->id))->timeout);
    Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
    Http::assertNothingSent();
});

test('job updates registered metadata without changing publication year', function (): void {
    $resource = createQueuedIgsn(
        ['doi' => '10.83279/JOB-UPDATE', 'publication_year' => 2018],
        ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
    );
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);

    Http::fake(['*datacite.org/*' => Http::response([
        'data' => ['id' => $resource->doi, 'type' => 'dois', 'attributes' => ['state' => 'findable']],
    ], 200)]);

    runQueuedIgsnRegistrationStep($run->id);

    $run->refresh();
    expect($run->updated)->toBe(1)
        ->and($run->registered)->toBe(0)
        ->and($resource->fresh()->publication_year)->toBe(2018);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('job isolates item failures and continues with later registrations', function (): void {
    $invalid = createQueuedIgsn(['doi' => '10.99999/INVALID-PREFIX']);
    $valid = createQueuedIgsn(['doi' => '10.83279/VALID-AFTER-FAILURE']);
    $run = app(IgsnRegistrationRunService::class)->start([$invalid->id, $valid->id], $this->curator);

    Http::fake(['*datacite.org/*' => Http::response([
        'data' => ['id' => $valid->doi, 'type' => 'dois', 'attributes' => ['state' => 'findable']],
    ], 201)]);

    runQueuedIgsnRegistrationStep($run->id);
    runQueuedIgsnRegistrationStep($run->id);

    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::COMPLETED)
        ->and($run->failed)->toBe(1)
        ->and($run->registered)->toBe(1);
    expect($invalid->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_ERROR)
        ->and($valid->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    Http::assertSentCount(1);
});

test('job fails only an item whose resource or landing page disappeared after queueing', function (string $race): void {
    $resource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);

    if ($race === 'resource') {
        $resource->delete();
    } else {
        $resource->landingPage()->delete();
    }

    runQueuedIgsnRegistrationStep($run->id);

    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::COMPLETED)
        ->and($run->processed)->toBe(1)
        ->and($run->failed)->toBe(1)
        ->and($run->items()->firstOrFail()->status)->toBe(IgsnRegistrationItemStatus::FAILED);
    Http::assertNothingSent();
})->with(['resource', 'landing page']);

test('job pauses before writing when the configured DataCite endpoint changed', function (): void {
    $resource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);
    config(['datacite.test.endpoint' => 'https://different.test.datacite.org']);

    runQueuedIgsnRegistrationStep($run->id);

    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::PAUSED)
        ->and($run->pause_reason)->toContain('mode or endpoint changed')
        ->and($run->processed)->toBe(0);
    Http::assertNothingSent();
});

test('resumed processing reconciles a remotely created IGSN without a duplicate create request', function (): void {
    $resource = createQueuedIgsn(['doi' => '10.83279/RECOVERED-REMOTE', 'publication_year' => 2017]);
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);
    $item = $run->items()->firstOrFail();
    $item->update([
        'status' => IgsnRegistrationItemStatus::PROCESSING,
        'attempts' => 1,
    ]);
    (new ProcessIgsnRegistrationRunJob($run->id))->failed(new RuntimeException('worker stopped after DataCite accepted the request'));

    $this->actingAs($this->curator)
        ->postJson(route('igsns.batch-register.resume', $run))
        ->assertOk()
        ->assertJsonPath('run.status', IgsnRegistrationRunStatus::QUEUED->value);

    Http::fake(fn (Request $request) => Http::response([
        'data' => [
            'id' => $resource->doi,
            'type' => 'dois',
            'attributes' => ['state' => 'findable'],
        ],
    ], $request->method() === 'GET' ? 200 : 201));

    runQueuedIgsnRegistrationStep($run->id);

    expect($run->fresh()->status)->toBe(IgsnRegistrationRunStatus::COMPLETED)
        ->and($run->fresh()->registered)->toBe(1)
        ->and($item->fresh()->status)->toBe(IgsnRegistrationItemStatus::REGISTERED)
        ->and($item->fresh()->attempts)->toBe(2)
        ->and($resource->fresh()->publication_year)->toBe((int) date('Y'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('retry failed resets only failed items and recalculates run counters', function (): void {
    $failedResource = createQueuedIgsn();
    $registeredResource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$failedResource->id, $registeredResource->id], $this->curator);
    $items = $run->items()->orderBy('id')->get();
    $items[0]->update([
        'status' => IgsnRegistrationItemStatus::FAILED,
        'error_message' => 'Temporary DataCite failure.',
        'processed_at' => now(),
    ]);
    $items[1]->update([
        'status' => IgsnRegistrationItemStatus::REGISTERED,
        'processed_at' => now(),
    ]);
    $run->update([
        'status' => IgsnRegistrationRunStatus::COMPLETED,
        'processed' => 2,
        'registered' => 1,
        'failed' => 1,
        'completed_at' => now(),
    ]);

    $this->actingAs($this->curator)
        ->postJson(route('igsns.batch-register.retry-failed', $run))
        ->assertOk()
        ->assertJsonPath('run.status', IgsnRegistrationRunStatus::QUEUED->value)
        ->assertJsonPath('run.processed', 1)
        ->assertJsonPath('run.registered', 1)
        ->assertJsonPath('run.failed', 0)
        ->assertJsonPath('run.can_retry_failed', false);

    expect($items[0]->fresh()->status)->toBe(IgsnRegistrationItemStatus::PENDING)
        ->and($items[0]->fresh()->error_message)->toBeNull()
        ->and($items[1]->fresh()->status)->toBe(IgsnRegistrationItemStatus::REGISTERED);
    Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
});

test('queued beginner registration uses test credentials without an auth context', function (): void {
    config(['datacite.test_mode' => false]);
    $beginner = User::factory()->beginner()->create();
    $resource = createQueuedIgsn(['doi' => '10.83279/BEGINNER-QUEUE']);
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $beginner);
    Auth::logout();

    Http::fake(['*datacite.org/*' => Http::response([
        'data' => ['id' => $resource->doi, 'type' => 'dois', 'attributes' => ['state' => 'findable']],
    ], 201)]);

    runQueuedIgsnRegistrationStep($run->id);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.test.datacite.org/dois')
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('TEST.USER:test-password'));
    expect($run->fresh()->status)->toBe(IgsnRegistrationRunStatus::COMPLETED);
});

test('job honours cancellation and marks remaining items terminal', function (): void {
    $first = createQueuedIgsn();
    $second = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$first->id, $second->id], $this->curator);
    $run->update(['status' => IgsnRegistrationRunStatus::CANCEL_REQUESTED]);

    runQueuedIgsnRegistrationStep($run->id);

    $run->refresh();
    expect($run->status)->toBe(IgsnRegistrationRunStatus::CANCELLED)
        ->and($run->processed)->toBe(2)
        ->and($run->cancelled)->toBe(2);
    expect($run->items()->where('status', IgsnRegistrationItemStatus::CANCELLED)->count())->toBe(2);
    Http::assertNothingSent();
});

test('failed job pauses the run and releases a processing item for resume', function (): void {
    $resource = createQueuedIgsn();
    $run = app(IgsnRegistrationRunService::class)->start([$resource->id], $this->curator);
    $item = $run->items()->firstOrFail();
    $item->update(['status' => IgsnRegistrationItemStatus::PROCESSING]);

    (new ProcessIgsnRegistrationRunJob($run->id))->failed(new RuntimeException('worker stopped'));

    expect($run->fresh()->status)->toBe(IgsnRegistrationRunStatus::PAUSED)
        ->and($item->fresh()->status)->toBe(IgsnRegistrationItemStatus::PENDING)
        ->and($run->fresh()->last_error)->toBe('worker stopped');
});
