<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\EditorDraftSaveIntent;
use App\Exceptions\DataCiteWriteSupersededException;
use App\Jobs\SendDataEmbargoNotificationJob;
use App\Jobs\SyncDataEmbargoAccessJob;
use App\Mail\DataEmbargoExpired;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use App\Services\DataCiteDoiWriteLockService;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataEmbargoNotificationService;
use App\Services\DataEmbargoReleaseService;
use App\Services\DataPublicationTeamRecipientService;
use App\Services\Editor\EditorResourceSaveService;
use App\Services\EmbargoService;
use App\Services\LandingPageContentLinkService;
use App\Services\ResourceStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function dataEmbargoFixture(string $day, bool $published = true): Resource
{
    $resource = Resource::factory()->create(['doi' => '10.83279/data-embargo-'.fake()->uuid(), 'access_level' => AccessLevel::EMBARGOED]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Public metadata with embargoed data']);
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $day]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id, 'template' => 'default_gfz', 'is_published' => $published,
        'published_at' => $published ? now() : null, 'downloads_unavailable' => false,
        'ftp_url' => 'https://example.org/embargoed-primary.zip',
    ]);

    return $resource->fresh(['landingPage', 'dates.dateType']);
}

beforeEach(function (): void {
    config(['app.timezone' => 'Europe/Berlin', 'mail.landing_page_contact_cc' => 'curation@example.org', 'datacite.test_mode' => true]);
    $this->travelTo(now('Europe/Berlin')->setDate(2026, 12, 30)->startOfDay());
});

test('public embargo metadata returns 200 while every stored data target is redacted', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $page = $resource->landingPage;
    $file = $page->files()->create(['url' => 'https://example.org/embargoed.csv', 'position' => 0]);
    $page->links()->create(['url' => 'https://example.org/private-repository', 'kind' => 'repository', 'label' => 'Repository', 'position' => 0]);

    $this->get($page->getPublicPath())->assertOk()->assertDontSee('embargoed-primary.zip')->assertDontSee('embargoed.csv')->assertDontSee('private-repository')->assertInertia(fn ($view) => $view
        ->where('isPreview', false)->where('embargoPending', true)->where('embargoDate', '2026-12-31')
        ->where('landingPage.ftp_url', null)->where('landingPage.files', [])->where('landingPage.links', []));
    $this->get(route('landing-page.download.primary', $page))->assertNotFound();
    $this->get(route('landing-page.download.file', ['landingPage' => $page, 'landingPageFile' => $file]))->assertNotFound();
    expect($page->fresh()->ftp_url)->toBe('https://example.org/embargoed-primary.zip');
    expect(app(LandingPageContentLinkService::class)->resolve($resource, $page))->toBe(['mimeType' => null, 'contentLinks' => [], 'repositories' => []]);
});

test('a future Available day protects inconsistent legacy Open records before normalization', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $resource->update(['access_level' => AccessLevel::OPEN]);
    expect(app(EmbargoService::class)->isEmbargoed($resource))->toBeTrue();
    $this->get(route('landing-page.download.primary', $resource->landingPage))->assertNotFound();
    app(EmbargoService::class)->normalizeResource($resource);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
});

test('normal saves cannot release an existing due embargo or publish its draft', function (): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    (void) app(ResourceStorageService::class)->store(['resourceId' => $resource->id, 'accessLevel' => 'open', 'titles' => [['title' => 'Embargo draft', 'titleType' => 'main-title']]]);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->landingPage->fresh()->is_published)->toBeFalse();
});

test('release is explicit, due, idempotent and preserves independent download suppression', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $service = app(DataEmbargoReleaseService::class);
    expect(fn () => $service->release($resource))->toThrow(ValidationException::class);
    $this->travelTo(now('Europe/Berlin')->addDay()->startOfDay());
    $resource->landingPage->update(['downloads_unavailable' => true]);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
    $service->release($resource);
    $service->release($resource);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeTrue()
        ->and($resource->dates()->first()->date_value)->toBe('2026-12-31')
        ->and($resource->landingPage->fresh()->downloads_unavailable)->toBeTrue();
    $this->get(route('landing-page.download.primary', $resource->landingPage))->assertNotFound();
});

test('release of a draft keeps it unpublished and requires authentication', function (): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    $url = route('resources.release-data-embargo', $resource);
    $this->postJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->curator()->create())->postJson($url)->assertOk()->assertJsonPath('released', true);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->landingPage->fresh()->is_published)->toBeFalse();
});

test('released access metadata is updated only after authenticated remote registration evidence', function (string $state, bool $allowed): void {
    $resource = dataEmbargoFixture('2026-12-29');
    config(['datacite.test.username' => 'test', 'datacite.test.password' => 'secret', 'datacite.test.prefixes' => ['10.83279'], 'datacite.test.endpoint' => 'https://api.test.datacite.org']);
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => $state]]])
        : Http::response(['data' => ['id' => $resource->doi]]));
    $service = app(DataEmbargoReleaseService::class);
    $released = $service->release($resource);
    if ($allowed) {
        $service->synchronize($released, app(DataCiteMemberApiClient::class));
        expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && ! isset($request->data()['data']['attributes']['event'])
            && collect($request->data()['data']['attributes']['rightsList'])->contains('rightsIdentifier', AccessLevel::OPEN->coarIdentifier()));
    } else {
        expect(fn () => $service->synchronize($released, app(DataCiteMemberApiClient::class)))->toThrow(RuntimeException::class);
        expect($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }
})->with([['findable', true], ['registered', true], ['draft', false], ['missing', false]]);

test('one individual email per resource and Available day survives repeated scans and duplicate jobs', function (): void {
    $resource = dataEmbargoFixture('2026-12-29');
    Queue::fake();
    Mail::fake();
    $notifier = app(DataEmbargoNotificationService::class);
    $notifier->dispatchDue();
    $notifier->dispatchDue();
    expect(DB::table('data_embargo_notifications')->count())->toBe(1);
    $job = new SendDataEmbargoNotificationJob((int) DB::table('data_embargo_notifications')->value('id'));
    $job->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    $job->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    $notifier->dispatchDue();
    Mail::assertSent(DataEmbargoExpired::class, 1);
    Mail::assertSent(DataEmbargoExpired::class, fn ($mail): bool => $mail->hasTo('curation@example.org') && $mail->identifier === $resource->doi);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
    $resource->dates()->first()->update(['date_value' => '2026-12-30']);
    $notifier->dispatchDue();
    expect(DB::table('data_embargo_notifications')->count())->toBe(2);
});

test('postponed, released, tombstoned and misconfigured notices do not send obsolete mail', function (): void {
    $resource = dataEmbargoFixture('2026-12-29');
    Queue::fake();
    Mail::fake();
    app(DataEmbargoNotificationService::class)->dispatchDue();
    $job = new SendDataEmbargoNotificationJob((int) DB::table('data_embargo_notifications')->value('id'));
    config(['mail.landing_page_contact_cc' => 'invalid']);
    $job->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    expect(DB::table('data_embargo_notifications')->value('status'))->toBe('pending');
    config(['mail.landing_page_contact_cc' => 'curation@example.org']);
    $resource->dates()->first()->update(['date_value' => '2027-01-01']);
    $job->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    Mail::assertNothingSent();
});

test('public embargo resources retain publication-level deletion and DOI protection', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $curator = User::factory()->curator()->create();
    expect($resource->publicStatus())->toBe('embargo')
        ->and($curator->can('delete', $resource))->toBeFalse()
        ->and($curator->can('editDoi', $resource))->toBeFalse();
});

test('a public embargo cannot be explicitly saved as a draft', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $this->actingAs(User::factory()->curator()->create())
        ->postJson('/editor/resources/draft', [
            'resourceId' => $resource->id, 'intent' => 'save-draft',
            'titles' => [['title' => 'Do not turn this into a draft', 'titleType' => 'main-title']],
        ])->assertUnprocessable()->assertJsonValidationErrors('intent');
    expect($resource->fresh()->workflow_status_override)->toBeNull()
        ->and($resource->fresh()->publicStatus())->toBe('embargo');
});

test('final saves validate the effective embargo under the row lock while autosaves stay relaxed', function (): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    $data = ['resourceId' => $resource->id, 'accessLevel' => 'open', 'dates' => [], 'titles' => [['title' => 'Still embargoed', 'titleType' => 'main-title']]];
    $service = app(EditorResourceSaveService::class);
    expect(fn () => $service->saveValidated($data, null))->toThrow(ValidationException::class);
    expect(app(EmbargoService::class)->availableDate($resource->fresh()))->toBe('2026-12-29');
    $service->saveRelaxed($data, null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and(app(EmbargoService::class)->availableDate($resource->fresh()))->toBeNull();
});

test('normalization preserves freshly eager-loaded dates without per-resource queries', function (): void {
    $ids = collect(range(1, 3))->map(fn () => dataEmbargoFixture('2026-12-31')->id);
    $resources = Resource::whereKey($ids)->with(['dates.dateType', 'landingPage'])->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        foreach ($resources as $resource) {
            app(EmbargoService::class)->normalizeResource($resource);
        }
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('registration claim markers stay consistent on the claimed model instance', function (): void {
    $resource = dataEmbargoFixture('2026-12-31');
    $policy = app(EmbargoService::class);
    expect($policy->claimRegistration($resource, '10.83279'))->toBeTrue()
        ->and($resource->embargo_registration_access_level)->toBe('embargoed')
        ->and($resource->embargo_registration_prefix)->toBe('10.83279')
        ->and($resource->embargo_registration_started_at)->not->toBeNull();
    $policy->clearRejectedRegistration($resource);
    expect($resource->embargo_registration_access_level)->toBeNull();
});

function embargoReleaseLimiter(): DataCiteRequestLimiter
{
    return new class extends DataCiteRequestLimiter
    {
        public int $milliseconds = 1_000_000;

        /** @var list<int> */
        public array $pauses = [];

        public ?Closure $duringPause = null;

        protected function nowMs(): int
        {
            return $this->milliseconds;
        }

        protected function pause(int $milliseconds): void
        {
            expect(DataCiteDoiWriteLockService::hasHeldLock())->toBeFalse();
            $this->pauses[] = $milliseconds;
            $this->milliseconds += $milliseconds;
            $callback = $this->duringPause;
            $this->duringPause = null;
            $callback?->__invoke();
        }
    };
}

test('access synchronization succeeds with the default nonzero request interval', function (): void {
    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);
    $resource = dataEmbargoFixture('2026-12-29');
    $limiter = embargoReleaseLimiter();
    $limiter->clear();
    Http::fake(fn ($request) => Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]]));
    $service = app(DataEmbargoReleaseService::class);
    $service->synchronize($service->release($resource), new DataCiteMemberApiClient($limiter, true));
    expect($limiter->pauses)->toBe([1000])
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Http::assertSentCount(2);
});

test('a Beginner editor supersedes production release metadata in the configured environment', function (): void {
    config(['datacite.test_mode' => false]);
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29');
    app(DataEmbargoReleaseService::class)->release($resource);
    $revision = DataCiteDoiWriteLockService::run($resource->doi, false,
        fn () => DataCiteDoiWriteLockService::observeWrite($resource->doi, false));
    app(EditorResourceSaveService::class)->saveRelaxed([
        'resourceId' => $resource->id, 'accessLevel' => 'open',
        'titles' => [['title' => 'Renewed production embargo', 'titleType' => 'main-title']],
        'dates' => [['dateType' => 'available', 'startDate' => '2027-01-15']],
    ], User::factory()->beginner()->create(), EditorDraftSaveIntent::AUTOSAVE);
    expect(fn () => DataCiteDoiWriteLockService::assertCurrentWrite($resource->doi, false, $revision))
        ->toThrow(DataCiteWriteSupersededException::class);
    Queue::assertPushed(SyncDataEmbargoAccessJob::class, fn ($job) => $job->resourceId === $resource->id && ! $job->testMode);
    Queue::assertNotPushed(SyncDataEmbargoAccessJob::class, fn ($job) => $job->testMode);
});

test('access synchronization rechecks publication after admission waits even without a cache revision change', function (string $change): void {
    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);
    $resource = dataEmbargoFixture('2026-12-29');
    $limiter = embargoReleaseLimiter();
    $limiter->clear();
    $limiter->duringPause = function () use ($resource, $change): void {
        $resource->landingPage->forceFill([$change => $change === 'is_tombstone'])->save();
    };
    Http::fake(fn ($request) => Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]]));
    $service = app(DataEmbargoReleaseService::class);
    $released = $service->release($resource);
    expect(fn () => $service->synchronize($released, new DataCiteMemberApiClient($limiter, true)))
        ->toThrow(DataCiteWriteSupersededException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    expect($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
})->with(['is_published', 'is_tombstone']);

test('an editor superseding release access queues a revision-checked corrective update', function (string $timing): void {
    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29');
    $limiter = embargoReleaseLimiter();
    $limiter->clear();
    $edit = function () use ($resource): void {
        app(EditorResourceSaveService::class)->saveRelaxed([
            'resourceId' => $resource->id, 'accessLevel' => 'open',
            'titles' => [['title' => 'New embargo metadata', 'titleType' => 'main-title']],
            'dates' => [['dateType' => 'available', 'startDate' => '2027-01-15']],
        ], null, EditorDraftSaveIntent::AUTOSAVE);
    };
    if ($timing === 'wait') {
        $limiter->duringPause = $edit;
    }
    $edited = false;
    Http::fake(function ($request) use ($resource, $timing, $edit, &$edited) {
        if ($request->method() === 'PUT' && $timing === 'write' && ! $edited) {
            $edited = true;
            $edit();
        }

        return Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]]);
    });
    $service = app(DataEmbargoReleaseService::class);
    $released = $service->release($resource);
    $superseded = false;
    try {
        $service->synchronize($released, new DataCiteMemberApiClient($limiter, true));
    } catch (DataCiteWriteSupersededException) {
        $superseded = true;
    }
    expect($superseded)->toBeTrue();
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
    Queue::assertPushed(SyncDataEmbargoAccessJob::class, fn ($job) => $job->resourceId === $resource->id && $job->testMode);
    if ($timing === 'wait') {
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }
    (new SyncDataEmbargoAccessJob($resource->id, true))->handle($service, $limiter);
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && collect($request->data()['data']['attributes']['rightsList'])->contains('rightsIdentifier', AccessLevel::EMBARGOED->coarIdentifier())
        && ! isset($request->data()['data']['attributes']['event']));
})->with(['wait', 'write']);
