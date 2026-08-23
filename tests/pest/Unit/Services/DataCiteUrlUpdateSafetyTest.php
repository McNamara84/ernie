<?php

declare(strict_types=1);

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Exceptions\DataCiteRequestDeferredException;
use App\Jobs\ProcessDataCiteUrlUpdateRunJob;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataCiteUrlUpdateCandidateService;
use App\Services\DataCiteUrlUpdateTargetService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'app.url' => 'https://dataservices.gfz.de',
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'secret',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 300,
        'datacite.landing_page_url_update.window_seconds' => 300,
    ]);

    Cache::flush();
    Queue::fake();
});

function createJobUrlMigrationResource(string $doi = '10.5880/job-test'): Resource
{
    $resource = Resource::factory()->create(['doi' => $doi, 'force_review_status' => true]);
    LandingPage::factory()->published()->withDoi($doi)->create([
        'resource_id' => $resource->id,
        'slug' => 'job-landing-page',
    ]);

    return $resource->fresh(['landingPage']);
}

/** @return array{DataCiteUrlUpdateRun, DataCiteUrlUpdateItem} */
function createPendingUrlMigrationJob(Resource $resource): array
{
    $run = DataCiteUrlUpdateRun::factory()->create(['total' => 1]);
    $item = DataCiteUrlUpdateItem::factory()->create([
        'run_id' => $run->id,
        'resource_id' => $resource->id,
        'identifier' => $resource->doi,
        'target_url' => 'https://dataservices.gfz.de'.$resource->landingPage->getPublicPath(),
    ]);

    return [$run, $item];
}

function handleUrlMigrationJob(ProcessDataCiteUrlUpdateRunJob $job): void
{
    $job->handle(
        app(DataCiteMemberApiClient::class),
        app(DataCiteRequestLimiter::class),
        app(DataCiteUrlUpdateCandidateService::class),
        app(DataCiteUrlUpdateTargetService::class),
    );
}

test('the limiter enforces both spacing and a hard rolling-window cap', function (): void {
    $clock = new class extends DataCiteRequestLimiter
    {
        public int $milliseconds = 1_000_000;

        protected function nowMs(): int
        {
            return $this->milliseconds;
        }
    };

    config([
        'datacite.landing_page_url_update.minimum_interval_ms' => 1000,
        'datacite.landing_page_url_update.requests_per_window' => 2,
        'datacite.landing_page_url_update.window_seconds' => 300,
    ]);

    expect($clock->reserveSlot())->toBe(0)
        ->and($clock->reserveSlot())->toBe(1000);

    $clock->milliseconds += 1000;
    expect($clock->reserveSlot())->toBe(0)
        ->and($clock->reserveSlot())->toBe(299000);

    $clock->imposeCooldown(60);
    expect($clock->reserveSlot())->toBe(60000);
});

test('queue callers can defer a long limiter wait without occupying the worker', function (): void {
    config([
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 1,
        'datacite.landing_page_url_update.window_seconds' => 300,
    ]);
    $limiter = app(DataCiteRequestLimiter::class);
    expect($limiter->reserveSlot())->toBe(0);

    expect(fn () => $limiter->waitForSlot(true))
        ->toThrow(DataCiteRequestDeferredException::class);
});

test('a rate-limited URL job is requeued without counting an HTTP attempt', function (): void {
    config([
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 1,
        'datacite.landing_page_url_update.window_seconds' => 300,
    ]);
    $resource = createJobUrlMigrationResource('10.5880/deferred');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    expect(app(DataCiteRequestLimiter::class)->reserveSlot())->toBe(0);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200)
        : Http::response([], 500));

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($item->fresh()->preflight_attempts)->toBe(0)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::RUNNING);
    Http::assertSentCount(1);
    Queue::assertPushed(ProcessDataCiteUrlUpdateRunJob::class);
});

test('the member client sends an authenticated JSON API partial URL update only', function (): void {
    Http::fake(['https://api.test.datacite.org/*' => Http::response([
        'data' => ['attributes' => ['url' => 'https://dataservices.gfz.de/new']],
    ])]);

    app(DataCiteMemberApiClient::class)->updateLandingPageUrl('10.5880/ABC 1', 'https://dataservices.gfz.de/new');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://api.test.datacite.org/dois/10.5880%2FABC%201'
            && $request->hasHeader('Authorization')
            && $request->hasHeader('User-Agent')
            && $request->data() === [
                'data' => [
                    'id' => '10.5880/ABC 1',
                    'type' => 'dois',
                    'attributes' => ['url' => 'https://dataservices.gfz.de/new'],
                ],
            ];
    });
});

test('the shared member client never retries permanent DataCite validation errors', function (): void {
    Http::fake([
        'https://api.test.datacite.org/*' => Http::sequence()
            ->push(['errors' => [['title' => 'Validation failed']]], 422)
            ->push(['data' => ['id' => 'must-not-be-reached']], 201),
    ]);

    $response = app(DataCiteMemberApiClient::class)->createDoi([
        'data' => ['type' => 'dois', 'attributes' => []],
    ]);

    expect($response->status())->toBe(422);
    Http::assertSentCount(1);
});

test('target reachability falls back to a bounded GET when HEAD is unsupported', function (): void {
    $targetUrl = 'https://dataservices.gfz.de/10.5880/example/landing';
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 405)
        : Http::response('', 200));

    expect(app(DataCiteUrlUpdateTargetService::class)->isReachable($targetUrl))->toBeTrue();

    $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all();
    expect($methods)->toBe(['HEAD', 'GET']);
});

test('the job performs a remote GET before a minimal PUT and completes the audit counters', function (): void {
    $resource = createJobUrlMigrationResource();
    [$run, $item] = createPendingUrlMigrationJob($resource);

    Http::fake(function (Request $request) use ($item) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        if ($request->method() === 'GET') {
            return Http::response(['data' => ['attributes' => [
                'url' => 'https://ernie.rz-vm499.gfz.de'.$item->resource->landingPage->getPublicPath(),
                'state' => 'findable',
            ]]]);
        }

        if ($request->method() === 'PUT') {
            return Http::response(['data' => ['attributes' => ['url' => $item->target_url]]]);
        }

        return Http::response([], 500);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));
    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_UPDATE)
        ->and($item->fresh()->before_url)->toStartWith('https://ernie.rz-vm499.gfz.de/');

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::UPDATED)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::COMPLETED)
        ->and($run->fresh()->processed)->toBe(1)
        ->and($run->fresh()->updated)->toBe(1)
        ->and($run->fresh()->active_marker)->toBeNull();

    $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all();
    expect($methods)->toBe(['HEAD', 'GET', 'HEAD', 'PUT']);

    Http::assertSent(function (Request $request) use ($item): bool {
        $data = $request->data();

        return $request->method() === 'PUT'
            && ($data['data']['attributes'] ?? null) === ['url' => $item->target_url];
    });
});

test('a missing remote DOI is skipped and can never become an accidental create', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/missing');
    [$run, $item] = createPendingUrlMigrationJob($resource);

    Http::fake(function (Request $request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['errors' => [['title' => 'Not found']]], 404);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::SKIPPED_REMOTE_MISSING)
        ->and($run->fresh()->skipped)->toBe(1)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::COMPLETED);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT' || $request->method() === 'POST');
});

test('an eligible internal ERNIE resource is updated from its previous URL to the current APP_URL', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/conflict');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    Http::fake(function (Request $request) use ($item) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        if ($request->method() === 'GET') {
            return Http::response(['data' => ['attributes' => ['url' => 'https://previous-ernie.example/landing', 'state' => 'findable']]]);
        }

        return Http::response(['data' => ['attributes' => ['url' => $item->target_url]]]);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));
    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_UPDATE)
        ->and($item->fresh()->before_url)->toBe('https://previous-ernie.example/landing');

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::UPDATED)
        ->and($run->fresh()->updated)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('local eligibility is rechecked and external pages are skipped without any HTTP request', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/changed-external');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    $resource->landingPage->update(['template' => 'external']);
    Http::preventStrayRequests();

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::SKIPPED_NO_LONGER_ELIGIBLE)
        ->and($run->fresh()->skipped)->toBe(1);
    Http::assertNothingSent();
});

test('authentication failures pause the run without processing or retrying the item', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/auth');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    Http::fake(function (Request $request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['errors' => [['title' => 'Unauthorized']]], 401);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($item->fresh()->last_http_status)->toBe(401)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::PAUSED)
        ->and($run->fresh()->processed)->toBe(0)
        ->and($run->fresh()->pause_reason)->toContain('authentication');
});

test('an already current URL completes without a PUT', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/current');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    Http::fake(function (Request $request) use ($item) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['data' => ['attributes' => ['url' => $item->target_url, 'state' => 'findable']]]);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::ALREADY_CURRENT)
        ->and($run->fresh()->already_current)->toBe(1)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::COMPLETED);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('a repeatedly rate-limited item pauses the run and imposes a shared cooldown', function (): void {
    config(['datacite.landing_page_url_update.max_transient_attempts' => 1]);
    $resource = createJobUrlMigrationResource('10.5880/rate-limit');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    Http::fake(function (Request $request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['errors' => [['title' => 'Too many requests']]], 429, ['Retry-After' => '90']);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($item->fresh()->preflight_attempts)->toBe(1)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::PAUSED)
        ->and(app(DataCiteRequestLimiter::class)->reserveSlot())->toBeGreaterThan(85_000);
});

test('a permanent server failure becomes an item failure after the configured attempts', function (): void {
    config(['datacite.landing_page_url_update.max_transient_attempts' => 1]);
    $resource = createJobUrlMigrationResource('10.5880/server-error');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    Http::fake(function (Request $request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['errors' => [['title' => 'Temporarily unavailable']]], 503);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::FAILED)
        ->and($item->fresh()->error_message)->toBe('Temporarily unavailable')
        ->and($run->fresh()->failed)->toBe(1)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::COMPLETED);
});

test('a changed runtime configuration pauses before any external request', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/config-change');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    config(['datacite.test.endpoint' => 'https://changed.test.datacite.org']);
    Http::preventStrayRequests();

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT)
        ->and($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::PAUSED)
        ->and($run->fresh()->pause_reason)->toContain('changed');
    Http::assertNothingSent();
});

test('a cancellation request becomes a terminal cancelled run without HTTP', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/cancel');
    [$run] = createPendingUrlMigrationJob($resource);
    $run->update(['status' => DataCiteUrlUpdateRunStatus::CANCEL_REQUESTED]);
    Http::preventStrayRequests();

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($run->fresh()->status)->toBe(DataCiteUrlUpdateRunStatus::CANCELLED)
        ->and($run->fresh()->active_marker)->toBeNull()
        ->and($run->fresh()->cancelled_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('an unexpected successful update response is recorded as failed', function (): void {
    $resource = createJobUrlMigrationResource('10.5880/bad-confirmation');
    [$run, $item] = createPendingUrlMigrationJob($resource);
    $item->update([
        'status' => DataCiteUrlUpdateItemStatus::PENDING_UPDATE,
        'before_url' => 'https://ernie.rz-vm499.gfz.de/old',
    ]);
    Http::fake(function (Request $request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200)
            : Http::response(['data' => ['attributes' => ['url' => 'https://wrong.example/value']]]);
    });

    handleUrlMigrationJob(new ProcessDataCiteUrlUpdateRunJob($run->id));

    expect($item->fresh()->status)->toBe(DataCiteUrlUpdateItemStatus::FAILED)
        ->and($item->fresh()->error_message)->toContain('did not confirm')
        ->and($run->fresh()->failed)->toBe(1);
});
