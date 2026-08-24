<?php

declare(strict_types=1);

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Jobs\ProcessDataCiteUrlUpdateRunJob;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config([
        'app.url' => 'https://dataservices.gfz.de',
        'queue.default' => 'database',
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'secret',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 300,
    ]);

    Cache::flush();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
});

function createUrlMigrationResource(
    string $doi,
    bool $published = true,
    bool $external = false,
    bool $forcePublishedStatus = true,
): Resource {
    $resource = Resource::factory()->create([
        'doi' => $doi,
        'force_review_status' => $forcePublishedStatus,
    ]);

    $factory = LandingPage::factory()
        ->withDoi($doi)
        ->state(['resource_id' => $resource->id, 'slug' => 'landing-page-'.$resource->id]);

    if ($published) {
        $factory = $factory->published();
    } else {
        $factory = $factory->draft();
    }

    if ($external) {
        $factory = $factory->external();
    }

    $factory->create();

    return $resource->fresh(['landingPage']);
}

function createUrlMigrationIgsn(string $igsn, string $status = IgsnMetadata::STATUS_REGISTERED): Resource
{
    $resource = createUrlMigrationResource($igsn);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => $status,
        'sample_type' => 'Rock',
        'material' => 'Granite',
    ]);

    return $resource->fresh(['landingPage', 'igsnMetadata']);
}

function fakeUrlMigrationPreviewRequests(): void
{
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD' && str_starts_with($request->url(), 'https://dataservices.gfz.de/')) {
            return Http::response('', 200);
        }

        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.test.datacite.org/dois/')) {
            $identifier = rawurldecode((string) str($request->url())->afterLast('/'));

            return Http::response([
                'data' => [
                    'id' => $identifier,
                    'type' => 'dois',
                    'attributes' => [
                        'url' => 'https://ernie.rz-vm499.gfz.de/old/'.$identifier,
                        'state' => 'findable',
                    ],
                ],
            ]);
        }

        return Http::response(['errors' => [['title' => 'Unexpected request']]], 500);
    });
}

test('only admins can access the URL migration API', function (): void {
    $curator = User::factory()->curator()->create();
    $run = DataCiteUrlUpdateRun::factory()->create();

    $this->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertUnauthorized();

    foreach (['beginner', 'curator', 'groupLeader'] as $roleState) {
        $user = User::factory()->{$roleState}()->create();

        $this->actingAs($user)
            ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
            ->assertForbidden();
    }

    $this->actingAs($curator);
    $this->postJson(route('datacite.url-updates.store'), ['scope' => 'resources'])->assertForbidden();
    $this->getJson(route('datacite.url-updates.show', $run))->assertForbidden();
    $this->getJson(route('datacite.url-updates.items', $run))->assertForbidden();
    $this->postJson(route('datacite.url-updates.cancel', $run))->assertForbidden();
    $this->postJson(route('datacite.url-updates.resume', $run))->assertForbidden();
    $this->postJson(route('datacite.url-updates.retry-failed', $run))->assertForbidden();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'invalid']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scope');
});

test('list pages expose the URL migration action and run details only to admins', function (): void {
    $run = DataCiteUrlUpdateRun::factory()->create();
    $curator = User::factory()->curator()->create();

    foreach (['resources', 'igsns.index'] as $routeName) {
        $this->actingAs($curator)
            ->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('canUpdateDataCiteLandingPageUrls', false)
                ->where('dataCiteUrlUpdateRun', null));

        $this->actingAs($this->admin)
            ->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('canUpdateDataCiteLandingPageUrls', true)
                ->where('dataCiteUrlUpdateRun.id', $run->id));
    }
});

test('resource preview shows at most ten eligible internal published landing pages', function (): void {
    foreach (range(1, 11) as $index) {
        createUrlMigrationResource("10.5880/preview.{$index}");
    }

    createUrlMigrationResource('10.5880/external', true, true);
    createUrlMigrationResource('10.5880/review', false);
    createUrlMigrationResource('10.5880/incomplete-draft', true, false, false);
    createUrlMigrationIgsn('10.60510/IGSN-EXCLUDED');
    fakeUrlMigrationPreviewRequests();

    $response = $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertOk()
        ->assertJsonPath('scope', 'resources')
        ->assertJsonPath('total', 11)
        ->assertJsonPath('sample_count', 10)
        ->assertJsonPath('can_start', true)
        ->assertJsonCount(10, 'items');

    foreach ($response->json('items') as $item) {
        expect($item['before_url'])->toStartWith('https://ernie.rz-vm499.gfz.de/')
            ->and($item['target_url'])->toStartWith('https://dataservices.gfz.de/')
            ->and($item['outcome'])->toBe('ready');
    }

    Http::assertSentCount(20);
});

test('external ERNIE resources never cause a DataCite lookup even when they have a DOI', function (): void {
    $external = createUrlMigrationResource('10.5880/external-never-read', true, true);
    Http::preventStrayRequests();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonPath('sample_count', 0)
        ->assertJsonCount(0, 'items');

    expect($external->landingPage->isExternal())->toBeTrue();
    Http::assertNothingSent();
});

test('IGSN preview includes only registered identifiers with published internal landing pages', function (): void {
    $eligible = createUrlMigrationIgsn('10.60510/IGSN-REGISTERED');
    createUrlMigrationIgsn('10.60510/IGSN-PENDING', IgsnMetadata::STATUS_PENDING);
    $external = createUrlMigrationIgsn('10.60510/IGSN-EXTERNAL');
    $external->landingPage->update(['template' => 'external']);
    createUrlMigrationResource('10.5880/RESOURCE-NOT-IGSN');
    fakeUrlMigrationPreviewRequests();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'igsns']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.resource_id', $eligible->id)
        ->assertJsonPath('items.0.identifier', '10.60510/IGSN-REGISTERED');
});

test('preview blocks the workflow when APP_URL is not an absolute HTTPS URL', function (): void {
    createUrlMigrationResource('10.5880/wrong-target');
    config(['app.url' => 'http://new-ernie.example']);
    Http::preventStrayRequests();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertOk()
        ->assertJsonPath('can_start', false)
        ->assertJsonPath('items.0.target_reachable', false);

    Http::assertNothingSent();
});

test('APP_URL alone defines the new domain and optional base path', function (): void {
    $resource = createUrlMigrationResource('10.5880/dynamic-app-url');
    config(['app.url' => 'https://new-ernie.example/catalogue']);
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD' && str_starts_with($request->url(), 'https://new-ernie.example/catalogue/')) {
            return Http::response('', 200);
        }

        return Http::response(['data' => ['attributes' => [
            'url' => 'https://ernie.rz-vm499.gfz.de/old-location',
            'state' => 'findable',
        ]]]);
    });

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertOk()
        ->assertJsonPath('target_base_url', 'https://new-ernie.example/catalogue')
        ->assertJsonPath(
            'items.0.target_url',
            'https://new-ernie.example/catalogue'.$resource->landingPage->getPublicPath(),
        )
        ->assertJsonPath('items.0.outcome', 'ready');
});

test('a non-persistent queue driver blocks preview and confirmed start regardless of its connection name', function (string $driver): void {
    config([
        'queue.default' => 'custom-url-migration-queue',
        'queue.connections.custom-url-migration-queue' => ['driver' => $driver],
    ]);
    Http::preventStrayRequests();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.preview', ['scope' => 'resources']))
        ->assertOk()
        ->assertJsonPath('can_start', false)
        ->assertJsonPath('blocking_message', 'A persistent queue connection is required for DataCite URL updates.');

    $this->postJson(route('datacite.url-updates.store'), ['scope' => 'resources'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('queue');
})->with(['sync', 'null']);

test('a non-persistent queue driver blocks resume and retry before changing persistent state', function (string $action, string $status, string $driver): void {
    config([
        'queue.default' => 'custom-url-migration-queue',
        'queue.connections.custom-url-migration-queue' => ['driver' => $driver],
    ]);

    $isRetry = $action === 'retry-failed';
    $run = DataCiteUrlUpdateRun::factory()->create([
        'status' => DataCiteUrlUpdateRunStatus::from($status),
        'active_marker' => null,
        'total' => 1,
        'processed' => $isRetry ? 1 : 0,
        'failed' => $isRetry ? 1 : 0,
    ]);
    $item = DataCiteUrlUpdateItem::factory()->create([
        'run_id' => $run->id,
        'status' => $isRetry
            ? DataCiteUrlUpdateItemStatus::FAILED
            : DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT,
        'preflight_attempts' => $isRetry ? 5 : 0,
        'update_attempts' => $isRetry ? 4 : 0,
        'processed_at' => $isRetry ? now() : null,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route("datacite.url-updates.{$action}", $run))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('queue');

    expect($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::from($status))
        ->and($run->fresh()->active_marker)->toBeNull()
        ->and($item->fresh()->status)->toBe($isRetry
            ? DataCiteUrlUpdateItemStatus::FAILED
            : DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($item->fresh()->preflight_attempts)->toBe($isRetry ? 5 : 0)
        ->and($item->fresh()->update_attempts)->toBe($isRetry ? 4 : 0);
    Queue::assertNothingPushed();
})->with([
    'resume via custom sync connection' => ['resume', 'cancelled', 'sync'],
    'resume via custom null connection' => ['resume', 'cancelled', 'null'],
    'retry via custom sync connection' => ['retry-failed', 'completed', 'sync'],
    'retry via custom null connection' => ['retry-failed', 'completed', 'null'],
]);

test('confirmation snapshots eligible records and enforces one global active run', function (): void {
    $eligible = createUrlMigrationResource('10.5880/start-me');
    createUrlMigrationResource('10.5880/skip-external', true, true);
    createUrlMigrationResource('10.5880/skip-review', false);

    $response = $this->actingAs($this->admin)
        ->postJson(route('datacite.url-updates.store'), ['scope' => 'resources'])
        ->assertAccepted()
        ->assertJsonPath('run.scope', 'resources')
        ->assertJsonPath('run.status', 'queued')
        ->assertJsonPath('run.total', 1);

    $run = DataCiteUrlUpdateRun::query()->findOrFail($response->json('run.id'));
    expect($run->items)->toHaveCount(1)
        ->and($run->items->first()->resource_id)->toBe($eligible->id)
        ->and($run->items->first()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT);

    Queue::assertPushed(
        ProcessDataCiteUrlUpdateRunJob::class,
        fn (ProcessDataCiteUrlUpdateRunJob $job): bool => $job->runId === $run->id && $job->afterCommit === true,
    );

    $this->postJson(route('datacite.url-updates.store'), ['scope' => 'igsns'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run');
});

test('an empty confirmed selection completes without dispatching a job', function (): void {
    createUrlMigrationResource('10.5880/external-only', true, true);

    $this->actingAs($this->admin)
        ->postJson(route('datacite.url-updates.store'), ['scope' => 'resources'])
        ->assertAccepted()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.total', 0);

    expect(DataCiteUrlUpdateRun::query()->active()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('all issue pages remain available for complete review', function (): void {
    $run = DataCiteUrlUpdateRun::factory()->create([
        'status' => DataCiteUrlUpdateRunStatus::COMPLETED,
        'active_marker' => null,
        'total' => 51,
        'processed' => 51,
        'skipped' => 51,
    ]);
    DataCiteUrlUpdateItem::factory()
        ->count(51)
        ->state([
            'run_id' => $run->id,
            'status' => DataCiteUrlUpdateItemStatus::SKIPPED_REMOTE_MISSING,
            'error_message' => 'The identifier was not found at DataCite.',
            'processed_at' => now(),
        ])
        ->create();

    $this->actingAs($this->admin)
        ->getJson(route('datacite.url-updates.items', ['run' => $run, 'issues' => 1, 'page' => 1]))
        ->assertOk()
        ->assertJsonCount(50, 'items')
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('pagination.total', 51);

    $this->getJson(route('datacite.url-updates.items', ['run' => $run, 'issues' => 1, 'page' => 2]))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('pagination.total', 51);
});

test('cancel resume and retry controls preserve a resumable audit trail', function (): void {
    $resource = createUrlMigrationResource('10.5880/control');
    $run = DataCiteUrlUpdateRun::factory()->create(['total' => 1]);
    $item = DataCiteUrlUpdateItem::factory()->create([
        'run_id' => $run->id,
        'resource_id' => $resource->id,
        'identifier' => $resource->doi,
        'status' => DataCiteUrlUpdateItemStatus::PENDING_UPDATE,
        'before_url' => 'https://ernie.rz-vm499.gfz.de/old',
        'target_url' => 'https://dataservices.gfz.de'.$resource->landingPage->getPublicPath(),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('datacite.url-updates.cancel', $run))
        ->assertOk()
        ->assertJsonPath('run.status', 'cancel_requested');

    $run->update([
        'status' => DataCiteUrlUpdateRunStatus::CANCELLED,
        'active_marker' => null,
        'cancelled_at' => now(),
    ]);

    $this->postJson(route('datacite.url-updates.resume', $run))
        ->assertOk()
        ->assertJsonPath('run.status', 'queued');

    $item->refresh();
    expect($item->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($item->before_url)->toBeNull();

    $run->update([
        'status' => DataCiteUrlUpdateRunStatus::COMPLETED,
        'active_marker' => null,
        'failed' => 1,
        'processed' => 1,
    ]);
    $item->update([
        'status' => DataCiteUrlUpdateItemStatus::FAILED,
        'processed_at' => now(),
        'error_message' => 'temporary failure',
        'preflight_attempts' => 5,
        'update_attempts' => 4,
    ]);

    $this->postJson(route('datacite.url-updates.retry-failed', $run))
        ->assertOk()
        ->assertJsonPath('run.status', 'queued')
        ->assertJsonPath('run.failed', 0)
        ->assertJsonPath('run.processed', 0);

    $retriedItem = $item->fresh();
    expect($retriedItem->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($retriedItem->preflight_attempts)->toBe(0)
        ->and($retriedItem->update_attempts)->toBe(0)
        ->and(Queue::pushed(ProcessDataCiteUrlUpdateRunJob::class))->toHaveCount(3)
        ->and(Queue::pushed(ProcessDataCiteUrlUpdateRunJob::class)
            ->every(fn (ProcessDataCiteUrlUpdateRunJob $job): bool => $job->afterCommit === true))->toBeTrue();
});
