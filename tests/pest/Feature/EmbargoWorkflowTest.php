<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteSyncService;
use App\Services\EmbargoService;
use App\Services\FakeDataCiteRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/DataCitePublicationFixtures.php';

beforeEach(function (): void {
    config(['app.url' => 'https://ernie.example']);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    config(['datacite.test.client_id' => 'test.repo', 'datacite.landing_page_url_update.minimum_interval_ms' => 0]);
});

function workflowEmbargo(string $date): Resource
{
    $resource = Resource::factory()->create(['doi' => null, 'access_level' => AccessLevel::EMBARGOED]);
    completeDataCiteFixture($resource);
    $resource->update(['access_level' => AccessLevel::EMBARGOED]);
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $date]);

    return $resource->fresh();
}

test('an embargo preview redacts every structured download target but shows the Available date', function (): void {
    $resource = workflowEmbargo('2027-01-01');
    $landingPage = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'template' => 'default_gfz',
        'doi_prefix' => null,
        'ftp_url' => 'https://example.org/private.zip',
    ]);
    $landingPage->files()->create(['url' => 'https://example.org/file.csv', 'position' => 0]);
    $landingPage->links()->create(['url' => 'https://example.org/download', 'label' => 'Download', 'position' => 0]);

    $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('embargoDate', '2027-01-01')
            ->where('embargoDue', false)
            ->where('landingPage.ftp_url', null)
            ->where('landingPage.tracked_ftp_url', null)
            ->where('landingPage.files', [])
            ->where('landingPage.links', []));
    $this->get('/datasets/'.$resource->id)->assertNotFound();
    $this->get($landingPage->getPublicPath())->assertNotFound();
});

test('an invalid embargo date still hides stored download targets in preview', function (): void {
    $resource = workflowEmbargo('2027');
    $landingPage = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => null,
        'template' => 'default_gfz',
        'ftp_url' => 'https://example.org/private.zip',
    ]);
    $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('embargoPending', true)
            ->where('embargoDate', null)
            ->where('landingPage.ftp_url', null));
});

test('a curator can publish embargo metadata but cannot use an external landing page', function (): void {
    $user = User::factory()->curator()->create();
    $resource = workflowEmbargo('2027-01-01');
    $this->actingAs($user)
        ->postJson("/resources/{$resource->id}/landing-page", ['template' => 'default_gfz', 'status' => 'draft'])->assertCreated();
    $this->actingAs($user)
        ->putJson("/resources/{$resource->id}/landing-page", ['template' => 'default_gfz', 'status' => 'published'])->assertOk();
    expect($resource->fresh()->landingPage->is_published)->toBeTrue();
    $this->actingAs($user)
        ->putJson("/resources/{$resource->id}/landing-page", ['template' => 'external'])->assertStatus(422);
});

test('due embargo notifications reach unassigned curators and change at midnight', function (): void {
    $resource = workflowEmbargo('2027-01-01');
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $user = User::factory()->curator()->create();

    $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'Europe/Berlin'));
    config(['app.timezone' => 'Europe/Berlin']);
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('dueEmbargoCount', 0));

    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('dueEmbargoCount', 1)
            ->where('dueEmbargos.0.id', $resource->id)
            ->where('dueEmbargos.0.availableDate', '2027-01-01'));
});

test('the resource list filters public Embargo metadata for a beginner editor', function (): void {
    $resource = workflowEmbargo('2027-01-01');
    $resource->update(['doi' => '10.83279/public-embargo']);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => null,
        'template' => 'default_gfz',
    ]);
    $beginner = User::factory()->beginner()->create();

    $this->actingAs($beginner)->get(route('resources', ['status' => ['embargo']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.publicstatus', 'embargo')
            ->where('resources.0.landingPage.public_url', $landingPage->public_url));
});

test('a data embargo does not prevent registration before the Available date', function (): void {
    $resource = workflowEmbargo('2099-01-01');
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    app(EmbargoService::class)->assertCanRegister($resource);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
});

test('manual DOI registration publishes metadata while keeping the data embargo', function (): void {
    config([
        'app.timezone' => 'Europe/Berlin',
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    $user = User::factory()->curator()->create();
    $resource = workflowEmbargo('2027-01-01');
    $landingPage = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => null,
        'template' => 'default_gfz',
    ]);
    Http::fake(fn (Request $request) => Http::response(dataCiteRecord('10.83279/RELEASED', attributes: $request->data()['data']['attributes'] ?? []), $request->method() === 'POST' ? 201 : 200));

    $this->actingAs($user)
        ->postJson(route('resources.publish-doi', $resource), ['prefix' => '10.83279', 'confirm_publication' => true])
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/RELEASED');

    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->doi)->toBe('10.83279/RELEASED')
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    $this->get('/datasets/'.$resource->id)
        ->assertRedirect($landingPage->fresh()->public_url);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['data']['attributes']['url'] === url('/datasets/'.$resource->id));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('resource batch updates never first-publish a due embargo', function (): void {
    $resource = workflowEmbargo('2020-01-01');
    $page = LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    Http::fake();
    $this->actingAs(User::factory()->curator()->create())
        ->postJson('/resources/batch-register', ['ids' => [$resource->id], 'prefix' => '10.83279'])
        ->assertStatus(207)->assertJsonPath('failed.0.id', $resource->id);
    expect($resource->fresh()->doi)->toBeNull()
        ->and($page->fresh()->is_published)->toBeFalse()
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED);
    Http::assertNothingSent();
});

test('manual IGSN registration preserves data embargo', function (): void {
    config([
        'app.timezone' => 'Europe/Berlin',
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Berlin'));
    $resource = workflowEmbargo('2027-01-01');
    $resource->doi = '10.83279/IGSN-RELEASED';
    $resource->save();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    $landingPage = LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $user = User::factory()->curator()->create();
    fakeIgsnMemberApi();

    $this->actingAs($user)
        ->postJson("/igsns/{$resource->id}/register")
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/IGSN-RELEASED');

    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->publication_year)->toBe(2027)
        ->and($metadata->fresh()->isRegistered())->toBeTrue()
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['data']['attributes']['url'] === url('/datasets/'.$resource->id)
        && collect($request->data()['data']['attributes']['rightsList'])
            ->contains('rightsIdentifier', AccessLevel::EMBARGOED->coarIdentifier()));
});

test('fake registration persists a future data embargo before publishing metadata', function (string $kind): void {
    config([
        'app.timezone' => 'Europe/Berlin',
        'datacite.test_mode' => true,
        'datacite.test.username' => null,
        'datacite.test.password' => null,
        'datacite.test.prefixes' => ['10.83279'],
    ]);
    $this->travelTo(Carbon::parse('2026-12-30 00:00:00', 'Europe/Berlin'));
    expect(app(DataCiteRegistrationService::class))->toBeInstanceOf(FakeDataCiteRegistrationService::class);
    $resource = workflowEmbargo('2026-12-31');
    $resource->update(['access_level' => AccessLevel::OPEN]);
    if ($kind === 'igsn') {
        $resource->update(['doi' => '10.83279/FAKE-FUTURE-IGSN']);
        IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    }
    $landingPage = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id, 'doi_prefix' => null, 'template' => 'default_gfz',
        'downloads_unavailable' => false, 'ftp_url' => 'https://example.org/private.zip',
    ]);
    $file = $landingPage->files()->create(['url' => 'https://example.org/private.csv', 'position' => 0]);
    Http::fake();
    $this->actingAs(User::factory()->curator()->create());

    $response = match ($kind) {
        'doi' => $this->postJson(route('resources.publish-doi', $resource), ['prefix' => '10.83279', 'confirm_publication' => true]),
        'batch-doi' => $this->postJson('/resources/batch-register', ['ids' => [$resource->id], 'prefix' => '10.83279']),
        'igsn' => $this->postJson("/igsns/{$resource->id}/register"),
    };
    if ($kind === 'batch-doi') {
        $response->assertStatus(207)->assertJsonPath('failed.0.id', $resource->id);
        expect($landingPage->fresh()->is_published)->toBeFalse()->and($resource->fresh()->doi)->toBeNull();
        Http::assertNothingSent();

        return;
    }
    $response->assertOk()->assertJsonPath('updated', false);
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->doi)->toStartWith('10.83279/')
        ->and($landingPage->fresh()->is_published)->toBeTrue();

    // The Available day alone must not release data after fake registration.
    $this->travelTo(Carbon::parse('2026-12-31 00:00:00', 'Europe/Berlin'));
    $this->get($landingPage->fresh()->getPublicPath())->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('embargoPending', true)->where('embargoDue', true)
            ->where('landingPage.ftp_url', null)->where('landingPage.files', []));
    $this->get(route('landing-page.download.primary', $landingPage))->assertNotFound();
    $this->get(route('landing-page.download.file', ['landingPage' => $landingPage, 'landingPageFile' => $file]))->assertNotFound();
    expect($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($landingPage->fresh()->ftp_url)->toBe('https://example.org/private.zip');
    Http::assertNothingSent();
})->with(['doi', 'batch-doi', 'igsn']);

test('uncertain embargo publications remain visible and reconcile without a second create', function (string $kind, bool $recover): void {
    config([
        'datacite.test_mode' => true, 'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password', 'datacite.test.prefixes' => ['10.83279'],
    ]);
    $resource = workflowEmbargo('2099-01-01');
    $doi = '10.83279/RECOVERED';
    if ($kind === 'igsn') {
        $resource->update(['doi' => $doi]);
        IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    }
    $page = LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $posts = 0;
    $accepted = null;
    Http::fake(function (Request $request) use (&$posts, &$accepted, $doi, $recover) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        if ($request->method() === 'POST') {
            $posts++;
            $accepted = dataCiteRecord($doi, attributes: $request->data()['data']['attributes']);

            return Http::response(['errors' => [['title' => 'Gateway timeout']]], 500);
        }
        if (str_contains($request->url(), '?')) {
            return Http::response(['data' => $recover && $accepted ? [$accepted['data']] : []]);
        }

        return $recover && $accepted ? Http::response($accepted) : Http::response([], 404);
    });
    $this->actingAs(User::factory()->curator()->create());
    $register = fn () => $kind === 'igsn'
        ? $this->postJson("/igsns/{$resource->id}/register")
        : $this->postJson(route('resources.publish-doi', $resource), ['prefix' => '10.83279', 'confirm_publication' => true]);
    $register()->assertStatus(202);
    expect($resource->fresh()->latestDoiPublication->status)->toBe('pending_reconciliation')
        ->and($page->fresh()->is_published)->toBeTrue();
    $register()->assertStatus($recover ? 200 : 202);
    expect($posts)->toBe(1)
        ->and($resource->fresh()->latestDoiPublication->status)->toBe($recover ? 'succeeded' : 'pending_reconciliation')
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($page->fresh()->is_published)->toBeTrue();
    if ($recover) {
        expect($resource->fresh()->doi)->toBe($doi);
    }
    if ($kind === 'igsn') {
        expect($resource->fresh()->igsnMetadata->isRegistered())->toBe($recover);
    }
})->with([['doi', true], ['doi', false], ['igsn', true], ['igsn', false]]);

test('an unpublished embargo page prevents existing DOI metadata updates before and after its date', function (string $date): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
    ]);
    $resource = workflowEmbargo($date);
    $resource->update(['doi' => '10.83279/EXISTING-EMBARGO']);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    Http::fake();

    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($resource))
        ->toThrow(InvalidArgumentException::class, 'published landing page');
    Http::assertNothingSent();
})->with(['2099-01-01', '2020-01-01', '2027']);

test('a stale public resource snapshot cannot publish an embargo draft DOI', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
    ]);
    $resource = Resource::factory()->create(['doi' => '10.83279/STALE-EMBARGO']);
    $page = LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => true]);
    $stale = $resource->fresh(['landingPage']);
    $resource->update(['access_level' => AccessLevel::EMBARGOED]);
    $page->update(['is_published' => false, 'published_at' => null]);
    Http::fake();

    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($stale))
        ->toThrow(InvalidArgumentException::class, 'published landing page');
    Http::assertNothingSent();
});

test('existing public embargo identifiers remain updatable without a publish event', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
    ]);
    $resource = workflowEmbargo('2099-01-01');
    $resource->update(['doi' => '10.83279/PUBLIC-LEGACY']);
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => true]);
    Http::fake(['*datacite.org/*' => Http::response(dataCiteRecord($resource->doi), 200)]);

    app(DataCiteRegistrationService::class)->updateMetadata($resource);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && ! array_key_exists('event', $request->data()['data']['attributes'])
        && $request->data()['data']['attributes']['url'] === $resource->landingPage->public_url);
});

test('automatic sync defers imported IGSNs with an embargo draft page', function (): void {
    $resource = workflowEmbargo('2099-01-01');
    $resource->update(['doi' => '10.60510/IMPORTED-EMBARGO']);
    IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_PENDING]);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    Http::fake();

    $result = app(DataCiteSyncService::class)->syncIfRegistered($resource);

    expect($result->attempted)->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($resource->fresh()->landingPage->is_published)->toBeFalse();
    Http::assertNothingSent();
});

test('manual and batch updates cannot publish an existing DOI behind an embargo draft', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.prefixes' => ['10.83279'],
    ]);
    $resource = workflowEmbargo('2099-01-01');
    $resource->update(['doi' => '10.83279/EXISTING-HTTP']);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $user = User::factory()->curator()->create();
    Http::fake();

    $this->actingAs($user)->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertStatus(422);
    $this->actingAs($user)->postJson('/resources/batch-register', ['ids' => [$resource->id], 'prefix' => '10.83279'])
        ->assertStatus(207)
        ->assertJsonPath('failed.0.id', $resource->id);
    Http::assertNothingSent();
});

test('a registered IGSN with a draft embargo page cannot be updated manually', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
    ]);
    $resource = workflowEmbargo('2099-01-01');
    $resource->update(['doi' => '10.83279/REGISTERED-EMBARGO']);
    IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_REGISTERED]);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    Http::fake();

    $this->actingAs(User::factory()->curator()->create())
        ->postJson("/igsns/{$resource->id}/register")
        ->assertStatus(422);
    Http::assertNothingSent();
});

test('an ambiguous create can reconcile using the same claimed Resource instance', function (string $kind): void {
    config([
        'datacite.test_mode' => true, 'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password', 'datacite.test.prefixes' => ['10.83279'],
    ]);
    $resource = workflowEmbargo('2099-01-01');
    $identifier = '10.83279/INSTANCE-RETRY';
    if ($kind === 'igsn') {
        $resource->update(['doi' => $identifier]);
        IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    }
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $posts = 0;
    $accepted = null;
    Http::fake(function (Request $request) use ($identifier, &$accepted, &$posts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        if ($request->method() === 'POST') {
            $posts++;
            $accepted = dataCiteRecord($identifier, attributes: $request->data()['data']['attributes']);

            return Http::response(['errors' => [['title' => 'Uncertain create']]], 500);
        }
        if (str_contains($request->url(), '?')) {
            return Http::response(['data' => $accepted ? [$accepted['data']] : []]);
        }

        return $accepted ? Http::response($accepted) : Http::response([], 404);
    });
    $service = app(DataCiteRegistrationService::class);
    $register = fn () => $kind === 'igsn' ? $service->registerIgsn($resource) : $service->registerDoi($resource, '10.83279');
    expect($register()['complete'])->toBeFalse();
    expect($resource->fresh()->latestDoiPublication->status)->toBe('pending_reconciliation');
    expect($register()['data']['id'])->toBe($identifier)->and($posts)->toBe(1);
})->with(['doi', 'igsn']);

test('the fake DataCite update also rejects an embargo draft', function (): void {
    $resource = workflowEmbargo('2099-01-01');
    $resource->update(['doi' => '10.83279/FAKE-EMBARGO']);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);

    expect(fn () => app(FakeDataCiteRegistrationService::class)->updateMetadata($resource))
        ->toThrow(InvalidArgumentException::class, 'published landing page');
});

test('automatic sync fails safely when an earlier public snapshot becomes an embargo draft', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
    ]);
    $resource = Resource::factory()->create(['doi' => '10.83279/STALE-SYNC']);
    $page = LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => true]);
    $stale = $resource->fresh(['landingPage']);
    $resource->update(['access_level' => AccessLevel::EMBARGOED]);
    $page->update(['is_published' => false, 'published_at' => null]);
    Http::fake();

    $result = app(DataCiteSyncService::class)->syncIfRegistered($stale);

    expect($result->hasFailed())->toBeTrue();
    Http::assertNothingSent();
});
