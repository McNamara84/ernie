<?php

declare(strict_types=1);

use App\Enums\EditorContext;
use App\Exceptions\ElmoCacheRefreshException;
use App\Jobs\RefreshElmoCacheJob;
use App\Models\PidSetting;
use App\Services\ElmoCacheRefreshDispatcherService;
use App\Services\ElmoCacheRefreshService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Helpers/ElmoCacheRefresh.php';

covers(ElmoCacheRefreshService::class, ElmoCacheRefreshDispatcherService::class, RefreshElmoCacheJob::class, ElmoCacheRefreshException::class);

beforeEach(function (): void {
    configureElmoCacheRefreshTests();
    Http::preventStrayRequests();
});

it('posts to the correct subpath with the matching secret and bounded request options', function (EditorContext $editor, string $path, string $key): void {
    $options = [];
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(elmoCacheRefreshSuccessResponse());
    });

    app(ElmoCacheRefreshService::class)->refresh($editor);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "https://stage.example/{$path}/api/v2/admin/cache/refresh"
        && $request->hasHeader('X-API-KEY', $key)
        && $request->hasHeader('Accept', 'application/json'));
    expect($options['timeout'])->toBe(310)
        ->and($options['connect_timeout'])->toBe(10)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['verify'] ?? true)->not->toBeFalse();
})->with([
    'ELMO' => [EditorContext::ELMO, 'elmo', 'elmo-secret'],
    'ELMO-MSL' => [EditorContext::ELMO_MSL, 'elmo-msl', 'msl-secret'],
]);

it('rejects incomplete or unsafe configuration before sending a request', function (string $field, mixed $value): void {
    config([$field => $value]);
    expect(app(ElmoCacheRefreshService::class)->isConfigured(EditorContext::ELMO))->toBeFalse();
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO))
        ->toThrow(ElmoCacheRefreshException::class, 'configuration is missing or invalid');
    Http::assertNothingSent();
})->with([
    'missing URL' => ['elmo.targets.elmo.base_url', ''],
    'malformed URL' => ['elmo.targets.elmo.base_url', 'bad-url'],
    'insecure URL' => ['elmo.targets.elmo.base_url', 'http://stage.example/elmo'],
    'URL credentials' => ['elmo.targets.elmo.base_url', 'https://user:secret@stage.example/elmo'],
    'URL query' => ['elmo.targets.elmo.base_url', 'https://stage.example/elmo?key=secret'],
    'URL fragment' => ['elmo.targets.elmo.base_url', 'https://stage.example/elmo#fragment'],
    'blank secret' => ['elmo.targets.elmo.api_key', '  '],
    'invalid header' => ['elmo.targets.elmo.api_key', "secret\r\nOther: value"],
    'connect timeout' => ['elmo.connect_timeout', 0],
    'connect exceeds HTTP' => ['elmo.connect_timeout', 311],
    'HTTP exceeds job' => ['elmo.timeout', 330],
    'job exceeds lease' => ['elmo.job_timeout', 390],
]);

it('classifies HTTP failures and never retains response secrets', function (int $status, bool $retryable): void {
    Http::fake(['*' => Http::response(['message' => 'elmo-secret', 'failed' => ['gemet', 'elmo-secret', 42]], $status)]);
    try {
        app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO);
        $this->fail('Expected an HTTP failure.');
    } catch (ElmoCacheRefreshException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->status)->toBe($status)
            ->and($exception->failedCaches)->toBe(['gemet'])
            ->and($exception->getMessage())->not->toContain('elmo-secret')
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([
    [301, false], [400, false], [401, false], [403, false], [404, false],
    [408, true], [429, true], [500, true], [502, true], [503, true], [504, true],
]);

it('bounds Retry-After values and accepts HTTP dates', function (string $header, ?int $delay): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => $header])]);
    try {
        app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO);
        $this->fail('Expected rate limiting.');
    } catch (ElmoCacheRefreshException $exception) {
        expect($exception->retryAfter)->toBe($delay);
    }
})->with([
    ['45', 45], ['999999', 300], ['0', null], ['-5', null], ['invalid', null],
    ['Wed, 01 Jan 2100 00:00:00 GMT', 300], ['Wed, 01 Jan 2020 00:00:00 GMT', null],
]);

it('rejects success responses that do not confirm every cache', function (mixed $body): void {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO))
        ->toThrow(ElmoCacheRefreshException::class, 'invalid success response');
})->with([
    ['not-json'], [null], [[]], [['success' => false, 'results' => ['gemet' => true]]],
    [['success' => true]], [['success' => true, 'results' => []]],
    [['success' => true, 'results' => [true]]],
    [['success' => true, 'results' => ['gemet' => false]]],
    [['success' => true, 'results' => ['gemet' => 1]]],
    [['success' => true, 'results' => 'wrong-type']],
    [['success' => true, 'results' => ['gemet' => true]]],
    [['success' => true, 'results' => ['unknownCache' => true]]],
    [['success' => true, 'results' => ['gemet' => true], 'failed' => ['gemet']]],
]);

it('rejects a refresh missing any required vocabulary result', function (string $cache): void {
    $body = elmoCacheRefreshSuccessResponse();
    unset($body['results'][$cache]);
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO))
        ->toThrow(ElmoCacheRefreshException::class, 'invalid success response');
})->with(array_keys(elmoCacheRefreshSuccessResponse()['results']));

it('requires strictly true results even in an otherwise complete response', function (mixed $value): void {
    $body = elmoCacheRefreshSuccessResponse();
    $body['results']['gemet'] = $value;
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO))
        ->toThrow(ElmoCacheRefreshException::class, 'invalid success response');
})->with([false, 1, 'true', null]);

it('accepts complete responses in any order with successful additional vocabularies', function (): void {
    $body = elmoCacheRefreshSuccessResponse();
    $body['results'] = array_reverse($body['results'], true);
    $body['results']['futureVocabulary'] = true;
    Http::fake(['*' => Http::response($body)]);
    app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO);
    Http::assertSentCount(1);
});

it('logs a truncated response as a failed job rather than a completed refresh', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'results' => ['gemet' => true]])]);
    Log::spy();
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->withFakeQueueInteractions();
    $job->handle(app(ElmoCacheRefreshService::class));
    $job->assertFailedWith(ElmoCacheRefreshException::class);
    Log::shouldNotHaveReceived('info');
});

it('requires both global enablement and MSL readiness for the MSL target', function (bool $enabled, bool $ready): void {
    config(['elmo.enabled' => $enabled, 'elmo.targets.elmo-msl.ready' => $ready]);
    $service = app(ElmoCacheRefreshService::class);
    expect($service->isEnabled())->toBe($enabled)
        ->and($service->isEnabled(EditorContext::ELMO))->toBe($enabled)
        ->and($service->isEnabled(EditorContext::ELMO_MSL))->toBe($enabled && $ready)
        ->and($service->isEnabled(EditorContext::ERNIE))->toBeFalse();
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('blocks direct refreshes when the integration or MSL target is disabled', function (bool $enabled, EditorContext $editor): void {
    config(['elmo.enabled' => $enabled, 'elmo.targets.elmo-msl.ready' => false]);
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh($editor))
        ->toThrow(ElmoCacheRefreshException::class, 'target is not enabled');
    Http::assertNothingSent();
})->with([[false, EditorContext::ELMO], [false, EditorContext::ELMO_MSL], [true, EditorContext::ELMO_MSL]]);

it('keeps configured but unready MSL out of the queue while refreshing ELMO', function (): void {
    config(['elmo.targets.elmo-msl.ready' => false]);
    Bus::fake();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO, EditorContext::ELMO_MSL], 'settings');
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    Bus::assertDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO);
    Bus::assertNotDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO_MSL);
});

it('rechecks enablement and readiness after commit', function (string $disabledFlag, int $count): void {
    Bus::fake();
    DB::beginTransaction();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO, EditorContext::ELMO_MSL], 'settings');
    config([$disabledFlag => false]);
    DB::commit();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, $count);
    Bus::assertNotDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO_MSL);
})->with([['elmo.enabled', 0], ['elmo.targets.elmo-msl.ready', 1]]);

it('skips an already queued MSL job if readiness is revoked before execution', function (): void {
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO_MSL, 'settings', 'correlation'))->withFakeQueueInteractions();
    config(['elmo.targets.elmo-msl.ready' => false]);
    $job->handle(app(ElmoCacheRefreshService::class));
    $job->assertNotFailed()->assertNotReleased();
    Http::assertNothingSent();
});

it('sanitizes connection exceptions and permits queue retries', function (): void {
    Http::fake(['*' => Http::failedConnection('elmo-secret')]);
    try {
        app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO);
        $this->fail('Expected connection failure.');
    } catch (ElmoCacheRefreshException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('elmo-secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('sanitizes unexpected request exceptions', function (): void {
    Http::fake(fn () => throw new RuntimeException('elmo-secret'));
    expect(fn () => app(ElmoCacheRefreshService::class)->refresh(EditorContext::ELMO))
        ->toThrow(ElmoCacheRefreshException::class, 'request could not be sent');
});

it('does not expose ERNIE as an outgoing target', function (): void {
    expect(app(ElmoCacheRefreshService::class)->isConfigured(EditorContext::ERNIE))->toBeFalse();
    Bus::fake();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ERNIE], 'test');
    Bus::assertNothingDispatched();
});

it('queues one credential-free job per distinct external target', function (): void {
    Bus::fake();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO, EditorContext::ELMO_MSL, EditorContext::ELMO], 'settings');
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 2);
    Bus::assertDispatched(RefreshElmoCacheJob::class, function (RefreshElmoCacheJob $job): bool {
        expect(serialize($job))->not->toContain('elmo-secret', 'msl-secret');

        return $job->connection === 'elmo' && $job->queue === 'elmo-cache-refresh' && $job->timeout === 330;
    });
});

it('waits for the surrounding transaction and discards rolled back refreshes', function (bool $commit): void {
    Bus::fake();
    DB::beginTransaction();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO], 'settings');
    Bus::assertNothingDispatched();
    if ($commit) {
        DB::commit();
        Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    } else {
        DB::rollBack();
        Bus::assertNothingDispatched();
    }
})->with([true, false]);

it('skips missing credentials without blocking the other target', function (): void {
    config(['elmo.targets.elmo.api_key' => '']);
    Bus::fake();
    Log::spy();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO, EditorContext::ELMO_MSL], 'settings');
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    Bus::assertDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO_MSL);
    Log::shouldHaveReceived('warning')->once();
});

it('isolates queue errors and logs no raw exception', function (): void {
    Bus::shouldReceive('dispatch')->once()->withArgs(fn ($job): bool => $job->editor === EditorContext::ELMO)
        ->andThrow(new RuntimeException('elmo-secret'));
    Bus::shouldReceive('dispatch')->once()->withArgs(fn ($job): bool => $job->editor === EditorContext::ELMO_MSL)->andReturn(null);
    Log::spy();
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO, EditorContext::ELMO_MSL], 'settings');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context): bool => ! str_contains(json_encode([$message, $context]), 'elmo-secret'));
});

it('skips dispatch and execution when disabled', function (): void {
    config(['elmo.enabled' => false]);
    Bus::fake();
    $dispatcher = app(ElmoCacheRefreshDispatcherService::class);
    $dispatcher->dispatch([EditorContext::ELMO], 'settings');
    $dispatcher->dispatch([], 'settings');
    Bus::assertNothingDispatched();
    (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->handle(app(ElmoCacheRefreshService::class));
    Http::assertNothingSent();
});

it('dispatches data updates only to currently active variants', function (bool $elmo, bool $msl, int $count): void {
    Bus::fake();
    $setting = new PidSetting(['type' => 'ror', 'is_elmo_active' => $elmo, 'is_elmo_msl_active' => $msl]);
    app(ElmoCacheRefreshDispatcherService::class)->dispatchForDataUpdate($setting);
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, $count);
})->with([[true, false, 1], [false, true, 1], [true, true, 2], [false, false, 0]]);

it('completes successful jobs without releasing or failing them', function (): void {
    Http::fake(['*' => Http::response(elmoCacheRefreshSuccessResponse())]);
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->withFakeQueueInteractions();
    $job->handle(app(ElmoCacheRefreshService::class));
    $job->assertNotFailed()->assertNotReleased();
    expect($job->backoff())->toBe([30, 120])->and($job->tries)->toBe(3);
});

it('fails permanent job errors immediately', function (): void {
    Http::fake(['*' => Http::response([], 401)]);
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->withFakeQueueInteractions();
    $job->handle(app(ElmoCacheRefreshService::class));
    $job->assertFailedWith(ElmoCacheRefreshException::class)->assertNotReleased();
});

it('passes transient job errors to the workers bounded retry policy', function (): void {
    Http::fake(['*' => Http::response([], 502)]);
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->withFakeQueueInteractions();
    expect(fn () => $job->handle(app(ElmoCacheRefreshService::class)))->toThrow(ElmoCacheRefreshException::class);
    $job->assertNotFailed()->assertNotReleased();
});

it('honors rate limiting but still exhausts its attempt budget', function (int $attempt): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);
    $job = (new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation'))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    if ($attempt < 3) {
        $job->handle(app(ElmoCacheRefreshService::class));
        $job->assertReleased(90);
    } else {
        expect(fn () => $job->handle(app(ElmoCacheRefreshService::class)))->toThrow(ElmoCacheRefreshException::class);
        $job->assertNotReleased();
    }
})->with([1, 3]);

it('logs a safe final failure for known and unknown exceptions', function (): void {
    Log::spy();
    $job = new RefreshElmoCacheJob(EditorContext::ELMO, 'test', 'correlation');
    $job->failed(new ElmoCacheRefreshException('safe', status: 502));
    $job->failed(new RuntimeException('elmo-secret'));
    $job->failed(null);
    Log::shouldHaveReceived('error')->times(3)
        ->withArgs(fn ($message, $context): bool => ! str_contains(json_encode([$message, $context]), 'elmo-secret'));
});

it('persists and executes a real queued refresh without serializing credentials', function (): void {
    Http::fake(['*' => Http::response(elmoCacheRefreshSuccessResponse())]);
    app(ElmoCacheRefreshDispatcherService::class)->dispatch([EditorContext::ELMO], 'test');
    $stored = DB::table('jobs')->where('queue', 'elmo-cache-refresh')->first();
    expect($stored)->not->toBeNull()
        ->and($stored->payload)->not->toContain('elmo-secret', 'msl-secret');
    config(['elmo.targets.elmo.api_key' => 'rotated-secret']);
    $queued = Queue::connection('elmo')->pop('elmo-cache-refresh');
    expect($queued)->not->toBeNull();
    $this->travel(310)->seconds();
    expect(Queue::connection('elmo')->pop('elmo-cache-refresh'))->toBeNull();
    $queued->fire();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-KEY', 'rotated-secret'));
    expect(DB::table('jobs')->where('queue', 'elmo-cache-refresh')->count())->toBe(0);
    $this->travelBack();
});
