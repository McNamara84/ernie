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
use Illuminate\Support\Facades\Bus;
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

test('release without a DOI keeps draft or missing landing pages unpublished and requires authentication', function (string $page): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    $resource->update(['doi' => null]);
    if ($page === 'missing') {
        $resource->landingPage->delete();
    }
    Http::fake();
    $url = route('resources.release-data-embargo', $resource);
    $this->postJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->curator()->create())->postJson($url)->assertOk()
        ->assertJsonPath('released', true)->assertJsonPath('synchronized', true);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeFalse()
        ->and($resource->fresh()->landingPage?->is_published)->toBe($page === 'missing' ? null : false);
    Http::assertNothingSent();
})->with(['draft', 'missing']);

test('registered draft or missing pages synchronize released access without changing publication', function (string $page, string $state): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    if ($page === 'missing') {
        $resource->landingPage->delete();
    }
    Http::fake(fn ($request) => Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => $state]]]));
    $this->actingAs(User::factory()->curator()->create())
        ->postJson(route('resources.release-data-embargo', $resource))->assertOk()
        ->assertJsonPath('released', true)->assertJsonPath('synchronized', true);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeFalse()
        ->and($resource->fresh()->landingPage?->is_published)->toBe($page === 'missing' ? null : false);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && array_keys($request->data()['data']['attributes']) === ['rightsList']
        && collect($request->data()['data']['attributes']['rightsList'])->contains('rightsIdentifier', AccessLevel::OPEN->coarIdentifier()));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with(['draft', 'missing'])->with(['registered', 'findable']);

test('failed access verification for a draft or missing page remains pending and can be retried', function (string $page): void {
    $resource = dataEmbargoFixture('2026-12-29', false);
    if ($page === 'missing') {
        $resource->landingPage->delete();
    }
    $registered = false;
    Http::fake(function ($request) use ($resource, &$registered) {
        return $registered
            ? Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'registered']]])
            : Http::response(['errors' => [['title' => 'Not registered']]], 404);
    });
    $this->actingAs(User::factory()->curator()->create())
        ->postJson(route('resources.release-data-embargo', $resource))->assertOk()
        ->assertJsonPath('released', true)->assertJsonPath('synchronized', false);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    $registered = true;
    $this->postJson(route('resources.release-data-embargo', $resource))->assertOk()->assertJsonPath('synchronized', true);
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse()
        ->and($resource->fresh()->landingPage?->is_published)->toBe($page === 'missing' ? null : false);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && array_keys($request->data()['data']['attributes']) === ['rightsList']);
})->with(['draft', 'missing']);

test('release uses the current registered DOI instead of a stale route binding', function (?string $boundDoi): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29', false);
    $resource->update(['doi' => $boundDoi]);
    $bound = $resource->fresh(['landingPage']);
    $doi = '10.83279/registered-after-binding';
    $revision = DataCiteDoiWriteLockService::run($doi, true, fn () => DataCiteDoiWriteLockService::observeWrite($doi, true));
    DB::transaction(function () use ($resource, $doi): void {
        $resource->update(['doi' => $doi]);
        $resource->landingPage->update(['is_published' => true, 'published_at' => now()]);
    });
    $locked = false;
    Resource::updating(function (Resource $current) use ($resource, &$locked): void {
        if ($current->id === $resource->id && $current->isDirty('access_level')) {
            $locked = DataCiteDoiWriteLockService::hasHeldLock();
        }
    });
    $service = app(DataEmbargoReleaseService::class);
    $released = $service->release($bound);
    expect($released->doi)->toBe($doi)
        ->and($released->data_embargo_sync_pending)->toBeTrue()
        ->and($locked)->toBeTrue();
    expect(fn () => DataCiteDoiWriteLockService::assertCurrentWrite($doi, true, $revision))
        ->toThrow(DataCiteWriteSupersededException::class);
    Http::fake(fn ($request) => Http::response(['data' => ['id' => $doi, 'attributes' => ['state' => 'findable']]]));
    $service->synchronize($released, app(DataCiteMemberApiClient::class));
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->data()['data']['id'] === $doi
        && collect($request->data()['data']['attributes']['rightsList'])->contains('rightsIdentifier', AccessLevel::OPEN->coarIdentifier()));
})->with([null, '10.83279/old-binding']);

test('a DOI assigned between lock selection and the row lock is retried before mutation', function (string $action): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29', false);
    $resource->update(['doi' => null, 'access_level' => $action === 'release' ? AccessLevel::EMBARGOED : AccessLevel::OPEN]);
    $bound = $resource->fresh();
    $doi = '10.83279/registered-during-selection';
    $revision = DataCiteDoiWriteLockService::run($doi, true, fn () => DataCiteDoiWriteLockService::observeWrite($doi, true));
    $registered = false;
    Resource::retrieved(function (Resource $snapshot) use ($resource, $doi, &$registered): void {
        if (! $registered && $snapshot->id === $resource->id) {
            $registered = true;
            // The identifier lookup has returned its old snapshot. Registration
            // commits before the release/editor obtains the resource row lock.
            DB::table('resources')->where('id', $resource->id)->update(['doi' => $doi]);
            DB::table('landing_pages')->where('resource_id', $resource->id)->update(['is_published' => true, 'published_at' => now()]);
        }
    });
    $current = $action === 'release' ? app(DataEmbargoReleaseService::class)->release($bound)
        : app(EditorResourceSaveService::class)->saveRelaxed([
            'resourceId' => $resource->id, 'accessLevel' => 'open',
            'titles' => [['title' => 'Registered during save', 'titleType' => 'main-title']],
            'dates' => [['dateType' => 'available', 'startDate' => '2027-01-15']],
        ], null, EditorDraftSaveIntent::AUTOSAVE)[0];
    expect($registered)->toBeTrue()
        ->and($current->doi)->toBe($doi)
        ->and($current->data_embargo_sync_pending)->toBeTrue()
        ->and($current->access_level)->toBe($action === 'release' ? AccessLevel::OPEN : AccessLevel::EMBARGOED);
    expect(fn () => DataCiteDoiWriteLockService::assertCurrentWrite($doi, true, $revision))
        ->toThrow(DataCiteWriteSupersededException::class);
})->with(['release', 'editor']);

test('a renewed embargo after a completed release marks and synchronizes access again', function (string $intent): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29');
    Http::fake(fn ($request) => Http::response(['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]]));
    $release = app(DataEmbargoReleaseService::class);
    $release->synchronize($release->release($resource), app(DataCiteMemberApiClient::class));
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    $data = [
        'resourceId' => $resource->id, 'accessLevel' => 'open',
        'titles' => [['title' => 'Renewed embargo', 'titleType' => 'main-title']],
        'dates' => [['dateType' => 'available', 'startDate' => '2027-01-15']],
    ];
    $editor = app(EditorResourceSaveService::class);
    $current = ($intent === 'validated' ? $editor->saveValidated($data, null)
        : $editor->saveRelaxed($data, null, EditorDraftSaveIntent::AUTOSAVE))[0];
    expect($current->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($current->data_embargo_sync_pending)->toBeTrue();
    Queue::assertPushed(SyncDataEmbargoAccessJob::class, 1);
    (new SyncDataEmbargoAccessJob($resource->id, true))->handle($release, app(DataCiteRequestLimiter::class));
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && collect($request->data()['data']['attributes']['rightsList'])->contains('rightsIdentifier', AccessLevel::EMBARGOED->coarIdentifier())
        && ! isset($request->data()['data']['attributes']['event']));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    Queue::fake();
    $editor->saveRelaxed($data, null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Queue::assertNotPushed(SyncDataEmbargoAccessJob::class);
})->with(['validated', 'autosave']);

test('other effective access changes on published identifiers are marked for access synchronization', function (AccessLevel $level): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29');
    $resource->update(['access_level' => AccessLevel::OPEN, 'data_embargo_sync_pending' => false]);
    app(EditorResourceSaveService::class)->saveRelaxed([
        'resourceId' => $resource->id, 'accessLevel' => $level->value,
        'titles' => [['title' => 'Changed access', 'titleType' => 'main-title']],
    ], null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
    Queue::assertPushed(SyncDataEmbargoAccessJob::class, 1);
})->with([AccessLevel::RESTRICTED, AccessLevel::METADATA_ONLY]);

test('normalizing a stored Open level under an existing future Available date also marks access pending', function (): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2027-01-15');
    $resource->update(['access_level' => AccessLevel::OPEN]);
    app(EditorResourceSaveService::class)->saveRelaxed([
        'resourceId' => $resource->id, 'accessLevel' => 'open',
        'titles' => [['title' => 'Normalize existing embargo', 'titleType' => 'main-title']],
    ], null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->data_embargo_sync_pending)->toBeTrue();
    Queue::assertPushed(SyncDataEmbargoAccessJob::class, 1);
});

test('unchanged access, drafts, missing identifiers and tombstones do not trigger a new access sync', function (string $case): void {
    Queue::fake();
    $resource = dataEmbargoFixture('2026-12-29', $case !== 'draft');
    $resource->update(['access_level' => AccessLevel::OPEN, 'doi' => $case === 'no-doi' ? null : $resource->doi]);
    if ($case === 'tombstone') {
        $resource->landingPage->forceFill(['is_tombstone' => true])->save();
    }
    app(EditorResourceSaveService::class)->saveRelaxed([
        'resourceId' => $resource->id, 'accessLevel' => 'open',
        'titles' => [['title' => 'Unchanged remote access', 'titleType' => 'main-title']],
        'dates' => [['dateType' => 'available', 'startDate' => $case === 'unchanged' ? '2020-01-01' : '2027-01-15']],
    ], null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->fresh()->data_embargo_sync_pending)->toBeFalse();
    Queue::assertNotPushed(SyncDataEmbargoAccessJob::class);
})->with(['unchanged', 'draft', 'no-doi', 'tombstone']);

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
    expect($notifier->dispatchDue())->toBe(1);
    $this->travel(2)->days();
    expect($notifier->dispatchDue())->toBe(0)
        ->and($notifier->dispatchDue())->toBe(0)
        ->and(DB::table('data_embargo_notifications')->count())->toBe(1)
        ->and(DB::table('data_embargo_notifications')->value('status'))->toBe('queued');
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 1);
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

test('invalid recipients keep one pending notice without queue churn and resume after correction', function (): void {
    $resource = dataEmbargoFixture('2026-12-29');
    Queue::fake();
    Mail::fake();
    config(['mail.landing_page_contact_cc' => 'invalid']);
    $notifier = app(DataEmbargoNotificationService::class);
    expect($notifier->dispatchDue())->toBe(0)
        ->and($notifier->dispatchDue())->toBe(0)
        ->and(DB::table('data_embargo_notifications')->count())->toBe(1)
        ->and(DB::table('data_embargo_notifications')->value('status'))->toBe('pending');
    Queue::assertNotPushed(SendDataEmbargoNotificationJob::class);
    config(['mail.landing_page_contact_cc' => 'curation@example.org']);
    expect($notifier->dispatchDue())->toBe(1)->and($notifier->dispatchDue())->toBe(0);
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 1);
    // Configuration may become invalid after the scheduler already queued a job.
    config(['mail.landing_page_contact_cc' => 'invalid']);
    (new SendDataEmbargoNotificationJob((int) DB::table('data_embargo_notifications')->value('id')))
        ->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    expect(DB::table('data_embargo_notifications')->value('status'))->toBe('pending')
        ->and($notifier->dispatchDue())->toBe(0);
    Mail::assertNothingSent();
    config(['mail.landing_page_contact_cc' => 'curation@example.org']);
    expect($notifier->dispatchDue())->toBe(1)->and($notifier->dispatchDue())->toBe(0);
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 2);
});

test('notification queue reservations roll back without dispatching jobs', function (): void {
    dataEmbargoFixture('2026-12-29');
    Queue::fake();
    $notifier = app(DataEmbargoNotificationService::class);
    DB::beginTransaction();
    expect($notifier->dispatchDue())->toBe(1)
        ->and(DB::table('data_embargo_notifications')->value('status'))->toBe('queued');
    Queue::assertNotPushed(SendDataEmbargoNotificationJob::class);
    DB::rollBack();
    expect(DB::table('data_embargo_notifications')->count())->toBe(0);
    Queue::assertNotPushed(SendDataEmbargoNotificationJob::class);
    expect($notifier->dispatchDue())->toBe(1);
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 1);
});

test('a broker failure after commit releases the notification reservation for retry', function (): void {
    dataEmbargoFixture('2026-12-29');
    $notifier = app(DataEmbargoNotificationService::class);
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue broker unavailable'));
    try {
        DB::beginTransaction();
        expect($notifier->dispatchDue())->toBe(1);
        expect(fn () => DB::commit())->toThrow(RuntimeException::class, 'Queue broker unavailable');
        expect(DB::table('data_embargo_notifications')->value('status'))->toBe('pending');
    } finally {
        Bus::swap($dispatcher);
    }
    Queue::fake();
    expect($notifier->dispatchDue())->toBe(1)->and($notifier->dispatchDue())->toBe(0);
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 1);
});

test('obsolete queued notifications release their reservation without sending mail', function (string $change): void {
    $resource = dataEmbargoFixture('2026-12-29');
    Queue::fake();
    Mail::fake();
    $notifier = app(DataEmbargoNotificationService::class);
    $notifier->dispatchDue();
    if ($change === 'postponed') {
        $resource->dates()->first()->update(['date_value' => '2027-01-15']);
    } elseif ($change === 'released') {
        $resource->update(['access_level' => AccessLevel::OPEN]);
    } else {
        $resource->landingPage->forceFill(['is_tombstone' => true])->save();
    }
    (new SendDataEmbargoNotificationJob((int) DB::table('data_embargo_notifications')->value('id')))
        ->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
    expect(DB::table('data_embargo_notifications')->value('status'))->toBe('pending')
        ->and($notifier->dispatchDue())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertPushed(SendDataEmbargoNotificationJob::class, 1);
})->with(['postponed', 'released', 'tombstone']);

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
