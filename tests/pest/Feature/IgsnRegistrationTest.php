<?php

declare(strict_types=1);

use App\Exceptions\DataCiteVerificationException;
use App\Jobs\ProcessIgsnRegistrationRunJob;
use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationRun;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceTombstoneTransition;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\IgsnRegistrationExclusionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../Support/DataCitePublicationFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.url' => 'https://ernie.example']);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    config([
        'datacite.test_mode' => false,
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
        'datacite.production.client_id' => 'prod.repo',
        'datacite.production.username' => 'PROD.USER',
        'datacite.production.password' => 'prod-password',
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
        'datacite.production.igsn_prefix' => '10.60510',
        'datacite.production.igsn_client_id' => 'igsn.repo',
        'datacite.production.igsn_username' => 'GFZ.IGSN',
        'datacite.production.igsn_password' => 'igsn-password',
        'queue.default' => 'database',
        'datacite.queue' => 'datacite',
    ]);

    Queue::fake();
    $this->user = User::factory()->curator()->create();
});

/**
 * Helper to create an IGSN resource with metadata.
 *
 * @param  array<string, mixed>  $resourceOverrides
 * @param  array<string, mixed>  $metadataOverrides
 */
function createIgsnWithMetadata(array $resourceOverrides = [], array $metadataOverrides = []): Resource
{
    $resource = Resource::factory()->create(array_merge([
        'doi' => '10.60510/IGSN-TEST-001',
        'resource_type_id' => ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object', 'is_active' => true])->id,
        'publication_year' => 2024,
    ], $resourceOverrides));

    completeDataCiteFixture($resource);
    if (isset($resourceOverrides['access_level'])) {
        $resource->update(['access_level' => $resourceOverrides['access_level']]);
    }

    IgsnMetadata::create(array_merge([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_UPLOADED,
        'sample_type' => 'Rock',
        'material' => 'Granite',
    ], $metadataOverrides));

    return $resource->fresh(['igsnMetadata']);
}

test('a team preview of a draft persists only on dismissal and matches the production request', function (string $identifier, string $doi) {
    config(['datacite.test_mode' => true, 'datacite.test.username' => null, 'datacite.test.password' => null]);
    $resource = createIgsnWithMetadata(['doi' => $identifier]);
    $page = LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => false]);
    fakeIgsnMemberApi();

    $runId = $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")
        ->assertOk()->assertJsonPath('mode', 'simulation')->json('run.id');
    $base = "/igsns/batch-register/{$runId}";
    $item = $this->getJson("{$base}/items")->assertOk()->assertJsonMissingPath('items.0.preview_payload')->json('items.0.id');
    $payload = $this->getJson("{$base}/items/{$item}/preview")->assertOk()
        ->assertJsonPath('payload.data.attributes.doi', $doi)
        ->assertJsonPath('payload.data.attributes.types.resourceTypeGeneral', 'PhysicalObject')
        ->assertJsonPath('payload.data.attributes.event', 'publish')
        ->assertJsonPath('payload.data.attributes.url', url('/datasets/'.$resource->id))->json('payload');
    expect($resource->fresh()->igsnMetadata->simulated_registered_at)->toBeNull();

    $colleague = User::factory()->curator()->create();
    $this->actingAs($colleague)->getJson("{$base}/items/{$item}/preview")->assertForbidden();
    $this->postJson("{$base}/confirm-preview")->assertForbidden();
    $this->actingAs($this->user)->postJson("{$base}/confirm-preview")->assertOk();
    $confirmed = $resource->fresh()->igsnMetadata->simulated_registered_at;
    $this->travel(5)->seconds();
    $this->postJson("{$base}/confirm-preview")->assertOk();
    expect($resource->fresh()->igsnMetadata->simulated_registered_at)->toEqual($confirmed)
        ->and($resource->fresh()->igsnMetadata->upload_status)->toBe('uploaded')
        ->and($resource->fresh()->doi)->toBe($identifier)->and($resource->fresh()->publication_year)->toBe(2024)
        ->and($page->fresh()->is_published)->toBeFalse();
    $this->actingAs($colleague)->get('/igsns?status=registered&sort=upload_status')->assertInertia(fn ($page) => $page
        ->where('igsns.0.upload_status', 'registered')->where('igsns.0.registration_operation', 'register')
        ->where('filterOptions.statuses', ['registered']));
    $this->getJson('/igsns/count?status=registered')->assertOk()->assertJsonPath('filtered_total', 1);
    Http::assertNothingSent();

    config(['datacite.test_mode' => false]);
    $this->getJson('/igsns/count?status=registered')->assertOk()->assertJsonPath('filtered_total', 0);
    $this->get('/igsns')->assertInertia(fn ($page) => $page->where('igsns.0.upload_status', 'uploaded'));
    $this->postJson("/igsns/{$resource->id}/register")->assertOk()->assertJsonPath('updated', false);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() == $payload);
    expect($resource->fresh()->doi)->toBe($doi)->and($resource->fresh()->igsnMetadata->isRegistered())->toBeTrue();
})->with([['10.60510/IGSN-TEST-001', '10.60510/IGSN-TEST-001'], ['ICDP1001', '10.60510/icdp1001']]);

test('a changed sample or mode cannot confirm a stale preview', function () {
    config(['datacite.test_mode' => true]);
    $resource = createIgsnWithMetadata();
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => false]);
    Http::fake();
    $id = $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")->assertOk()->json('run.id');
    config(['datacite.test_mode' => false]);
    $this->postJson("/igsns/batch-register/{$id}/confirm-preview")->assertConflict();
    config(['datacite.test_mode' => true]);
    $resource->titles()->update(['value' => 'Revised sample']);
    $this->postJson("/igsns/batch-register/{$id}/confirm-preview")->assertOk()->assertJsonPath('run.failed', 1);
    expect($resource->fresh()->igsnMetadata->simulated_registered_at)->toBeNull();
    Http::assertNothingSent();
});

test('an existing IGSN previews the update with its original publication year and current landing page', function (bool $withdrawn) {
    config(['datacite.test_mode' => true]);
    $resource = createIgsnWithMetadata([], ['upload_status' => 'registered']);
    $page = LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'is_tombstone' => $withdrawn]);
    $target = $withdrawn ? url('/datasets/'.$resource->id) : $page->public_url;
    if ($withdrawn) {
        ResourceTombstoneTransition::create(['resource_id' => $resource->id, 'doi' => $resource->doi, 'revision' => 1,
            'action' => 'activate', 'test_mode' => false, 'target_state' => 'registered', 'target_url' => $target, 'status' => 'succeeded']);
    }
    fakeIgsnMemberApi([$resource->doi => $withdrawn ? 'registered' : 'findable']);
    $id = $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")->assertOk()->json('run.id');
    $item = $this->getJson("/igsns/batch-register/{$id}/items")->assertJsonPath('items.0.operation', 'update')->json('items.0.id');
    $payload = $this->getJson("/igsns/batch-register/{$id}/items/{$item}/preview")
        ->assertJsonPath('payload.data.id', $resource->doi)->assertJsonPath('payload.data.attributes.publicationYear', '2024')
        ->assertJsonPath('payload.data.attributes.url', $target)->assertJsonMissingPath('payload.data.attributes.event')->json('payload');
    Http::assertNothingSent();

    config(['datacite.test_mode' => false]);
    $this->postJson("/igsns/{$resource->id}/register")->assertOk()->assertJsonPath('updated', true);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() == $payload);
})->with([false, true]);

test('production checks the IGSN repository before choosing a write for a historical badge', function (bool $alreadyRegistered) {
    $resource = createIgsnWithMetadata(['doi' => 'ICDP1001'], ['upload_status' => IgsnMetadata::STATUS_UNVERIFIED]);
    $doi = '10.60510/icdp1001';
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $alreadyRegistered]);
    fakeIgsnMemberApi($alreadyRegistered ? [$doi => 'findable'] : []);

    $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")->assertOk()->assertJsonPath('updated', $alreadyRegistered);

    $writes = Http::recorded(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT'], true));
    expect($writes)->toHaveCount(1);
    $request = $writes->first()[0];
    expect($request->method())->toBe($alreadyRegistered ? 'PUT' : 'POST')
        ->and($request['data']['attributes']['publicationYear'])->toBe($alreadyRegistered ? '2024' : (string) now()->year)
        ->and($resource->fresh()->doi)->toBe($doi)
        ->and($resource->fresh()->igsnMetadata->isRegistered())->toBeTrue();
})->with([true, false]);

test('a CSV handle has a routable public target when DataCite receives the request', function (bool $update, bool $legacyPage, bool $published) {
    $handle = 'ICDP1001';
    $doi = '10.60510/icdp1001';
    $resource = createIgsnWithMetadata(['doi' => $handle], ['upload_status' => $update ? 'registered' : 'uploaded']);
    // Normal setup derives the prefix from the sample. Older pages persisted the bare handle.
    $page = $resource->landingPage()->create([
        'template' => 'default_gfz', 'slug' => 'borehole', 'is_published' => $published,
        ...($legacyPage ? ['doi_prefix' => $handle] : []),
    ]);
    $canonical = url('/'.$doi.'/borehole');
    $stable = url('/datasets/'.$resource->id);
    $expectedTarget = $update ? $canonical : $stable;
    $expectedPrefix = $legacyPage ? $handle : $doi;
    expect($page->doi_prefix)->toBe($expectedPrefix);
    $headUrls = [];
    Http::fake(function (Request $request) use ($resource, $handle, $doi, $update, $stable, $canonical, $expectedTarget, &$headUrls) {
        if ($request->method() === 'HEAD') {
            // Dispatch both the reachability probe and its redirect through ERNIE's real public router.
            $headUrls[] = $request->url();
            $response = $this->head($request->url());

            return Http::response('', $response->status(), $response->headers->all());
        }
        if ($request->method() === 'GET') {
            return $update ? Http::response(dataCiteRecord($doi, 'registered', 'igsn.repo')) : Http::response([], 404);
        }
        expect($resource->fresh()->doi)->toBe($handle)
            ->and($request['data']['attributes']['url'])->toBe($expectedTarget);
        $redirect = $this->get($stable)->assertRedirect($canonical);
        $this->get($redirect->headers->get('Location'))->assertOk();

        return Http::response(dataCiteRecord($doi, $update ? 'registered' : 'findable', 'igsn.repo', $request['data']['attributes']));
    });

    config(['datacite.test_mode' => true]);
    $runId = $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")->assertOk()->json('run.id');
    $preview = IgsnRegistrationRun::findOrFail($runId)->items()->sole()->preview_payload;
    expect($preview['data']['attributes']['url'])->toBe($expectedTarget)
        ->and($page->fresh()->doi_prefix)->toBe($expectedPrefix)
        ->and($page->fresh()->is_published)->toBe($published)
        ->and($resource->fresh()->doi)->toBe($handle);
    Http::assertNothingSent();

    config(['datacite.test_mode' => false]);
    Cache::put("landing-page.{$handle}.borehole", true);
    Cache::put("landing-page.{$doi}.borehole", true);
    $service = app(DataCiteRegistrationService::class);
    auth()->logout();
    $response = $update ? $service->updateMetadata($resource) : $service->registerIgsn($resource);
    expect($response['data']['id'])->toBe($doi)
        ->and($headUrls)->toBe($update ? [] : [$stable, $canonical])
        ->and($resource->fresh()->doi)->toBe($doi)
        ->and($page->fresh()->doi_prefix)->toBe($doi)
        ->and($page->fresh()->public_url)->toBe($canonical)
        ->and(Cache::has("landing-page.{$handle}.borehole"))->toBeFalse()
        ->and(Cache::has("landing-page.{$doi}.borehole"))->toBeFalse()
        ->and(OaiPmhDeletedRecord::where('doi', $handle)->exists())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === ($update ? 'PUT' : 'POST') && $request->data() == $preview);
    $this->followingRedirects()->get($expectedTarget)->assertOk();
})->with([
    'new landing page' => [false, false, false],
    'legacy draft landing page' => [false, true, false],
    'legacy published landing page' => [false, true, true],
    'existing DOI with a new landing page' => [true, false, true],
    'existing DOI with a legacy page' => [true, true, true],
]);

test('a concurrent DOI edit survives confirmation of a metadata update for the original CSV handle', function () {
    $resource = createIgsnWithMetadata(['doi' => 'ICDP1001'], ['upload_status' => 'registered']);
    $page = LandingPage::factory()->published()->withDoi('ICDP1001')->create(['resource_id' => $resource->id]);
    $doi = '10.60510/icdp1001';
    $edited = '10.60510/curator-edit';
    Http::fake(function (Request $request) use ($resource, $doi, $edited) {
        if ($request->method() === 'PUT') {
            $resource->fresh()->update(['doi' => $edited]);
        }

        return Http::response(dataCiteRecord($doi, 'registered', 'igsn.repo'));
    });

    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($resource))
        ->toThrow(RuntimeException::class, 'The IGSN changed while DataCite was updating its metadata.');
    expect($resource->fresh()->doi)->toBe($edited)->and($page->fresh()->doi_prefix)->toBe($edited);
});

test('a rejected metadata update keeps the CSV handle and its routable landing page for a retry', function () {
    $resource = createIgsnWithMetadata(['doi' => 'ICDP1001'], ['upload_status' => 'registered']);
    $page = LandingPage::factory()->published()->withDoi('ICDP1001')->create(['resource_id' => $resource->id]);
    $doi = '10.60510/icdp1001';
    Http::fake(fn (Request $request) => $request->method() === 'PUT'
        ? Http::response([], 422)
        : Http::response(dataCiteRecord($doi, 'registered', 'igsn.repo')));

    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($resource))
        ->toThrow(RequestException::class);
    expect($resource->fresh()->doi)->toBe('ICDP1001')->and($page->fresh()->doi_prefix)->toBe($doi)
        ->and(OaiPmhDeletedRecord::where('doi', 'ICDP1001')->exists())->toBeFalse();
    $this->followingRedirects()->get('/datasets/'.$resource->id)->assertOk();
});

test('an already normalized resource repairs a legacy bare landing-page prefix on its next metadata update', function () {
    $doi = '10.60510/icdp1001';
    $resource = createIgsnWithMetadata(['doi' => $doi], ['upload_status' => 'registered']);
    $page = LandingPage::factory()->published()->withDoi('ICDP1001')->create(['resource_id' => $resource->id]);
    fakeIgsnMemberApi([$doi => 'registered']);

    app(DataCiteRegistrationService::class)->updateMetadata($resource);

    $page->refresh();
    expect($page->doi_prefix)->toBe($doi);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['data']['attributes']['url'] === $page->public_url);
    $this->followingRedirects()->get('/datasets/'.$resource->id)->assertOk();
});

test('an external target changed during verification prevents the stale metadata PUT', function () {
    $resource = createIgsnWithMetadata(['doi' => 'ICDP1001'], ['upload_status' => 'registered']);
    $page = LandingPage::factory()->external()->published()->withDoi('ICDP1001')->create(['resource_id' => $resource->id]);
    Http::fake(function () use ($page) {
        $page->update(['external_path' => 'corrected-sample']);

        return Http::response(dataCiteRecord('10.60510/icdp1001', 'registered', 'igsn.repo'));
    });

    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($resource))
        ->toThrow(RuntimeException::class, 'The landing page changed before the metadata update.');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect($page->fresh()->doi_prefix)->toBe('ICDP1001')->and($resource->fresh()->doi)->toBe('ICDP1001');
});

test('editing an unregistered CSV handle invalidates both canonical landing-page URLs', function () {
    $resource = createIgsnWithMetadata(['doi' => 'ICDP1001']);
    $page = $resource->landingPage()->create(['template' => 'default_gfz', 'slug' => 'borehole']);
    foreach (['10.60510/icdp1001', '10.60510/icdp1002'] as $doi) {
        Cache::put("landing-page.{$doi}.borehole", true);
    }
    $resource->update(['doi' => 'ICDP1002']);
    expect($page->fresh()->doi_prefix)->toBe('10.60510/icdp1002')
        ->and(Cache::has('landing-page.10.60510/icdp1001.borehole'))->toBeFalse()
        ->and(Cache::has('landing-page.10.60510/icdp1002.borehole'))->toBeFalse();
});

test('a preview uses the configured external URL without contacting the landing page', function () {
    config(['datacite.test_mode' => true]);
    $resource = createIgsnWithMetadata();
    $page = LandingPage::factory()->external()->create(['resource_id' => $resource->id, 'external_path' => 'samples/rock']);
    Http::fake();

    $id = $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")->assertOk()->json('run.id');
    $item = $this->getJson("/igsns/batch-register/{$id}/items")->json('items.0.id');
    $this->getJson("/igsns/batch-register/{$id}/items/{$item}/preview")->assertOk()
        ->assertJsonPath('payload.data.attributes.url', $page->external_url);
    Http::assertNothingSent();
});

// ============================================================================
// Service: registerIgsn()
// ============================================================================

describe('DataCiteRegistrationService::registerIgsn', function () {
    test('registers an IGSN keeping the existing DOI in the payload', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $service = app(DataCiteRegistrationService::class);
        $response = $service->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.60510/IGSN-TEST-001');

        // Verify the DOI was kept in the payload (not unset like registerDoi)
        // and publicationYear is always set to current year
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'api.datacite.org/dois')
                && $request->method() === 'POST'
                && isset($body['data']['attributes']['doi'])
                && $body['data']['attributes']['doi'] === '10.60510/IGSN-TEST-001'
                && isset($body['data']['attributes']['publicationYear'])
                && $body['data']['attributes']['publicationYear'] === (string) date('Y');
        });
    });

    test('extracts prefix from IGSN automatically', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.60510/SAMPLE-XYZ']);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $service = app(DataCiteRegistrationService::class);
        $response = $service->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.60510/SAMPLE-XYZ');

        // Verify the correct prefix was sent
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST' && str_starts_with($body['data']['attributes']['doi'], '10.60510/');
        });
    });

    test('uses the dedicated repository credentials when registering a production IGSN', function () {
        config(['datacite.test_mode' => false]);
        $resource = createIgsnWithMetadata(['doi' => '10.60510/GFTEST001']);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $response = app(DataCiteRegistrationService::class)->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.60510/GFTEST001');
        Http::assertSent(fn (Request $request): bool => ($request->header('Authorization')[0] ?? null)
            === 'Basic '.base64_encode('GFZ.IGSN:igsn-password'));
    });

    test('rejects IGSN with invalid DOI format (missing suffix)', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.83279']);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(DataCiteVerificationException::class, 'not permitted');
    });

    test('rejects IGSN with invalid prefix', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.99999/INVALID-PREFIX']);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(DataCiteVerificationException::class, 'not permitted');
    });

    test('requires a landing page before registering', function () {
        $resource = createIgsnWithMetadata();
        // No landing page created

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(RuntimeException::class, 'landing page is required');
    });

    test('requires an IGSN (doi) to register', function () {
        $resource = createIgsnWithMetadata(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(RuntimeException::class, 'IGSN must be assigned');
    });
});

// ============================================================================
// Controller: IgsnController@registerAtDataCite (single registration)
// ============================================================================

describe('IgsnController@registerAtDataCite', function () {
    test('registers an IGSN at DataCite successfully', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'doi' => '10.60510/IGSN-TEST-001',
                'updated' => false,
            ]);

        // Verify status was updated to registered
        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });

    test('sets publicationYear to current year on registration', function () {
        $resource = createIgsnWithMetadata(['publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register")
            ->assertOk();

        $resource->refresh();
        expect($resource->publication_year)->toBe((int) date('Y'));
    });

    test('rejects registration without landing page', function () {
        $resource = createIgsnWithMetadata();
        // No landing page

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Landing page required',
            ]);
    });

    test('rejects single registration while the IGSN is queued in an active batch run', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);
        Http::fake();

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertAccepted();

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register")
            ->assertConflict()
            ->assertJsonPath('error', 'Registration already queued');

        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('rejects single registration while the shared resource lock is held', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);
        Http::fake();
        $lock = app(IgsnRegistrationExclusionService::class)->resourceLock($resource->id);
        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($this->user)
                ->postJson("/igsns/{$resource->id}/register")
                ->assertConflict()
                ->assertJsonPath('error', 'Registration in progress');
        } finally {
            $lock->release();
        }

        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('updates metadata for already-registered IGSN', function () {
        $resource = createIgsnWithMetadata(
            ['publication_year' => 2020],
            ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
        );
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi([$resource->doi => 'findable']);

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'updated' => true,
            ]);

        // Verify PUT was used instead of POST
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT';
        });

        // publicationYear must NOT be overwritten for already-registered IGSNs
        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
    });

    test('marks IGSN as error on API failure', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        Http::fake(fn (Request $request) => match ($request->method()) {
            'GET' => Http::response([], 404),
            'HEAD' => Http::response('', 200),
            default => Http::response(['errors' => [['title' => 'Bad Request']]], 400),
        });

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        // DataCite 400 maps to 400 (client error preserved)
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'DataCite API error',
            'message' => 'Bad Request',
        ]);

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_ERROR);
        expect($resource->igsnMetadata->upload_error_message)->not->toBeNull();
    });

    test('keeps the previous IGSN state when verification is retryable', function (string $missingEvidence, string $status): void {
        $previousError = $status === IgsnMetadata::STATUS_ERROR ? 'An earlier registration failed.' : null;
        $resource = createIgsnWithMetadata(['publication_year' => 2020], [
            'upload_status' => $status,
            'upload_error_message' => $previousError,
        ]);
        $registered = $status === IgsnMetadata::STATUS_REGISTERED;
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $registered]);
        $record = dataCiteRecord($resource->doi);
        data_forget($record, $missingEvidence);
        $verificationUnavailable = true;
        Http::fake(function () use ($record, &$verificationUnavailable) {
            return $verificationUnavailable ? Http::response($record) : null;
        });

        $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")
            ->assertStatus(503)
            ->assertJsonPath('error', 'doi_verification_unavailable')
            ->assertJsonPath('message', 'DataCite did not confirm the DOI identity, repository and state. No publication can be inferred.');

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe($status)
            ->and($resource->igsnMetadata->upload_error_message)->toBe($previousError)
            ->and($resource->publication_year)->toBe(2020);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');

        $verificationUnavailable = false;
        fakeIgsnMemberApi($registered ? [$resource->doi => 'findable'] : []);
        $this->postJson("/igsns/{$resource->id}/register")
            ->assertOk()->assertJsonPath('updated', $registered);
        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    })->with(['data.id', 'data.attributes.state', 'data.relationships.client.data.id'])
        ->with([IgsnMetadata::STATUS_UPLOADED, IgsnMetadata::STATUS_REGISTERED, IgsnMetadata::STATUS_ERROR]);

    test('returns a definitive verification failure for a foreign IGSN repository', function (string $status): void {
        $resource = createIgsnWithMetadata([], ['upload_status' => $status]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $status === IgsnMetadata::STATUS_REGISTERED]);
        Http::fake(fn () => Http::response(dataCiteRecord($resource->doi, repository: 'foreign.repo')));

        $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'doi_repository_mismatch')
            ->assertJsonPath('message', 'This DOI belongs to another DataCite repository.');

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_ERROR)
            ->and($resource->igsnMetadata->upload_error_message)->toBe('This DOI belongs to another DataCite repository.');
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    })->with([IgsnMetadata::STATUS_UPLOADED, IgsnMetadata::STATUS_REGISTERED]);

    test('allows retrying IGSN publication after a temporarily unreachable landing page', function (): void {
        $resource = createIgsnWithMetadata(['publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => false]);
        $targetUnavailable = true;
        Http::fake(function (Request $request) use (&$targetUnavailable) {
            return $targetUnavailable ? Http::response([], $request->method() === 'HEAD' ? 503 : 404) : null;
        });

        $this->actingAs($this->user)->postJson("/igsns/{$resource->id}/register")
            ->assertStatus(503)
            ->assertJsonPath('error', 'doi_target_unreachable')
            ->assertJsonPath('message', 'The approved landing page is not publicly reachable. DOI publication has not been sent.');

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED)
            ->and($resource->igsnMetadata->upload_error_message)->toBeNull()
            ->and($resource->publication_year)->toBe(2020)
            ->and($resource->landingPage->is_published)->toBeTrue();
        Http::assertSent(fn (Request $request) => $request->method() === 'HEAD');
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT'], true));

        $targetUnavailable = false;
        fakeIgsnMemberApi();
        $this->postJson("/igsns/{$resource->id}/register")->assertOk()->assertJsonPath('updated', false);
        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });

    test('does not persist publicationYear on API failure', function () {
        $resource = createIgsnWithMetadata(['publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        Http::fake(fn (Request $request) => match ($request->method()) {
            'GET' => Http::response([], 404),
            'HEAD' => Http::response('', 200),
            default => Http::response(['errors' => [['title' => 'Bad Request']]], 400),
        });

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $resource->refresh();
        // publication_year should NOT have been updated because the DataCite call failed
        expect($resource->publication_year)->toBe(2020);
    });

    test('returns 404 for non-IGSN resource', function () {
        $resource = Resource::factory()->create();
        // No igsnMetadata

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertStatus(404);
    });

    test('allows beginners to register IGSNs in test mode', function () {
        $beginner = User::factory()->beginner()->create();
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        config(['datacite.test_mode' => false]);

        fakeIgsnMemberApi();

        $response = $this->actingAs($beginner)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()->assertJsonPath('mode', 'simulation')->assertJsonPath('run.is_simulation', true);
        Http::assertNothingSent();
        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
    });
});

// ============================================================================
// Controller: BatchIgsnRegistrationController@register
// ============================================================================

describe('BatchIgsnRegistrationController@register', function () {
    test('batch registers multiple IGSNs successfully', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.60510/BATCH-001']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.60510/BATCH-002']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        LandingPage::factory()->create(['resource_id' => $resource2->id]);

        fakeIgsnMemberApi();

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('run.total', 2)
            ->assertJsonPath('run.status', 'queued');

        Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
        Http::assertNothingSent();
    });

    test('reports failures for IGSNs without landing pages', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.60510/LP-YES']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.60510/LP-NO']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        // resource2 has no landing page

        fakeIgsnMemberApi();

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('ids.1');

        expect(IgsnRegistrationRun::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    test('sets publicationYear for newly registered IGSNs', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.60510/YEAR-001', 'publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertAccepted()
            ->assertJsonPath('run.total', 1);

        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
    });

    test('preserves publicationYear for already-registered IGSNs in batch', function () {
        $resource = createIgsnWithMetadata(
            ['doi' => '10.60510/YEAR-002', 'publication_year' => 2020],
            ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
        );
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        fakeIgsnMemberApi();

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertAccepted()
            ->assertJsonPath('run.total', 1);

        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
    });

    test('validates request requires ids', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', []);

        $response->assertStatus(422);
    });

    test('isolates failures from successes in batch', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.60510/OK-001']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.99999/BAD-PREFIX']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        LandingPage::factory()->create(['resource_id' => $resource2->id]);

        fakeIgsnMemberApi();

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('run.total', 2);

        expect($resource1->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED)
            ->and($resource2->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('allows beginners to batch register IGSNs in test mode', function () {
        $beginner = User::factory()->beginner()->create();
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        config(['datacite.test_mode' => false]);

        fakeIgsnMemberApi();

        $response = $this->actingAs($beginner)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]]);

        $response->assertAccepted()
            ->assertJsonPath('run.test_mode', true)
            ->assertJsonPath('run.is_simulation', true);

        Http::assertNothingSent();

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
    });
});

// ============================================================================
// IgsnController: transformResource includes has_landing_page
// ============================================================================

describe('IgsnController@index includes has_landing_page', function () {
    test('uses the resource-specific creator snapshot for the collector', function () {
        $resource = createIgsnWithMetadata();
        $resource->creators()->delete();
        $person = Person::factory()->create([
            'given_name' => 'Philipp',
            'family_name' => 'Sommer',
        ]);
        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $resource->id,
            'position' => 1,
            'name_snapshot' => 'Sommer, Philipp S.',
            'given_name_snapshot' => 'Philipp S.',
            'family_name_snapshot' => 'Sommer',
        ]);

        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertOk();
        $igsn = collect($response->original->getData()['page']['props']['igsns'])
            ->firstWhere('id', $resource->id);
        expect($igsn['collector'])->toBe('Sommer, Philipp S.');
    });

    test('returns has_landing_page true when landing page exists', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $resource->igsnMetadata?->isRegistered() ?? false]);

        $response = $this->actingAs($this->user)
            ->get('/igsns');

        $response->assertOk();
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $igsn = collect($igsns)->firstWhere('id', $resource->id);
        expect($igsn['has_landing_page'])->toBeTrue();
    });

    test('returns has_landing_page false when no landing page', function () {
        $resource = createIgsnWithMetadata();
        // No landing page

        $response = $this->actingAs($this->user)
            ->get('/igsns');

        $response->assertOk();
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $igsn = collect($igsns)->firstWhere('id', $resource->id);
        expect($igsn['has_landing_page'])->toBeFalse();
    });
});
