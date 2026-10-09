<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\ResourceWorkflowStatus;
use App\Enums\UserRole;
use App\Http\Controllers\EditorController;
use App\Jobs\SyncResourceTombstoneWithDataCiteJob;
use App\Models\ContactMessage;
use App\Models\DateType;
use App\Models\Description;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageFile;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceTombstoneTransition;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Models\User;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\KeywordSuggestionService;
use App\Services\LandingPageMachineMetadataService;
use App\Services\PortalCacheInvalidationService;
use App\Services\PortalSearchService;
use App\Services\ResourceTombstoneSyncService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([SyncResourceTombstoneWithDataCiteJob::class]);
    config([
        'datacite.test_mode' => true,
        'datacite.test.client_id' => 'test.repository',
        'datacite.test.username' => 'test.repository',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
    ]);
    $this->user = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->resource = Resource::factory()->create(['doi' => '10.83279/tombstone']);
    Title::factory()->create(['resource_id' => $this->resource->id, 'value' => 'A lost dataset']);
    ResourceCreator::factory()->create(['resource_id' => $this->resource->id]);
    $this->page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'doi_prefix' => $this->resource->doi, 'is_published' => true, 'published_at' => now()->subYear(), 'ftp_url' => 'https://example.org/data.zip']);
    $this->endpoint = "/resources/{$this->resource->id}/landing-page/tombstone";
    $this->payload = ['revision' => 0, 'reason' => 'data_lost', 'statement' => 'The original files were permanently lost.', 'confirmed' => true];
    $this->remote = fn (string $state = 'findable', ?string $url = null): array => [
        'data' => ['id' => $this->resource->doi, 'type' => 'dois', 'attributes' => ['state' => $state, 'url' => $url ?? $this->page->public_url], 'relationships' => ['client' => ['data' => ['id' => 'test.repository']]]],
    ];
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)())]);
});

test('curator roles activate a public tombstone without losing its URL or files', function (UserRole $role) {
    $this->user->update(['role' => $role]);
    $url = $this->page->public_url;
    $publishedAt = $this->page->published_at->toIso8601String();
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)
        ->assertOk()->assertJsonPath('publicstatus', 'dead')->assertJsonPath('tombstone.sync.status', 'pending');
    $page = $this->page->fresh();
    expect($page->is_tombstone)->toBeTrue()
        ->and($page->public_url)->toBe($url)
        ->and($page->ftp_url)->toBe('https://example.org/data.zip')
        ->and($page->published_at->toIso8601String())->toBe($publishedAt)
        ->and(ResourceTombstoneTransition::first()->snapshot['configuration']['ftp_url'])->toBe($page->ftp_url);
    Http::assertSentCount(1);
})->with([UserRole::CURATOR, UserRole::GROUP_LEADER, UserRole::ADMIN]);

test('logs tombstone activation and asynchronous completion with the original actor', function () {
    $this->user->update(['name' => 'Original Curator']);
    Log::spy();
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $transition = ResourceTombstoneTransition::firstOrFail();
    $this->user->update(['name' => 'Renamed Curator']);
    Http::swap(new Factory);
    Http::fake(fn ($request) => Http::response(($this->remote)('registered', $this->page->public_url)));
    $sync = app(ResourceTombstoneSyncService::class);
    $sync->sync($transition->id);
    $sync->sync($transition->id);
    expect($transition->fresh()->status)->toBe('succeeded');
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'landing-page.tombstone.activate'
        && in_array('is_tombstone', $context['activity']['changed_fields'], true))->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'tombstone.datacite_synced'
        && $context['activity']['actor']['name'] === 'Original Curator')->once();
});

test('beginner cannot activate edit restore or retry a tombstone', function (string $method, string $suffix) {
    $this->user->update(['role' => UserRole::BEGINNER]);
    $this->actingAs($this->user)->json($method, $this->endpoint.$suffix, $this->payload)->assertForbidden();
    Http::assertNothingSent();
})->with([['POST', ''], ['PATCH', ''], ['DELETE', ''], ['POST', '/retry-sync']]);

test('an unchanged tombstone explanation creates neither a transition nor an activity', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Log::spy();
    $payload = [...$this->payload, 'revision' => 1];
    $this->patchJson($this->endpoint, $payload)->assertOk();
    expect(ResourceTombstoneTransition::count())->toBe(1)->and($this->page->fresh()->tombstone_revision)->toBe(1);
    Log::shouldNotHaveReceived('info');
});

test('activation requires a reason public explanation confirmation and revision', function (string $field, mixed $value) {
    $this->payload[$field] = $value;
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->page->fresh()->is_tombstone)->toBeFalse();
    Http::assertNothingSent();
})->with([['reason', 'invalid'], ['statement', '   '], ['statement', str_repeat('x', 5001)], ['confirmed', false], ['revision', -1]]);

test('remote drafts and DOIs owned by another repository cannot become tombstones', function (string $state, string $owner) {
    $remote = ($this->remote)($state);
    $remote['data']['relationships']['client']['data']['id'] = $owner;
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response($remote)]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('doi');
    expect($this->page->fresh()->is_tombstone)->toBeFalse()->and(ResourceTombstoneTransition::count())->toBe(0);
})->with([['draft', 'test.repository'], ['findable', 'other.repository']]);

test('a registered DOI can become a tombstone using the authenticated member API', function () {
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization'));
    expect(ResourceTombstoneTransition::first()->previous_state)->toBe('registered');
});

test('missing DOI and missing citation metadata prevent activation', function (string $missing) {
    if ($missing === 'doi') {
        $this->resource->update(['doi' => null]);
    } else {
        $this->resource->creators()->delete();
    }
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertUnprocessable();
    expect($this->page->fresh()->is_tombstone)->toBeFalse();
})->with(['doi', 'creators']);

test('external configuration is preserved and restored without reverting metadata edits', function () {
    $domain = LandingPageDomain::factory()->create();
    $this->page->update(['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'original', 'ftp_url' => null]);
    $originalUrl = $this->page->fresh()->public_url;
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered', $originalUrl))]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk()->assertJsonPath('landing_page.template', 'default_gfz');
    $this->resource->update(['version' => '2.0']);
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])
        ->assertOk()->assertJsonPath('publicstatus', 'published')->assertJsonPath('landing_page.external_url', $originalUrl);
    expect($this->resource->fresh()->version)->toBe('2.0')
        ->and($this->page->fresh()->external_domain_id)->toBe($domain->id)
        ->and(ResourceTombstoneTransition::latest('id')->first()->target_state)->toBe('registered');
});

test('statement edits are audited and stale edits cannot replace a newer state', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->patchJson($this->endpoint, ['revision' => 1, 'reason' => 'retracted', 'statement' => 'This resource has been retracted.'])->assertOk();
    $this->patchJson($this->endpoint, ['revision' => 1, 'reason' => 'other', 'statement' => 'Stale explanation.'])->assertConflict();
    $this->postJson($this->endpoint, $this->payload)->assertConflict();
    expect(ResourceTombstoneTransition::count())->toBe(2)
        ->and(ResourceTombstoneTransition::first()->statement)->toBe($this->payload['statement'])
        ->and($this->page->fresh()->tombstone_statement)->toBe('This resource has been retracted.');
});

test('normal landing page updates and submitted tombstone fields cannot bypass the lifecycle', function () {
    $this->actingAs($this->user)->putJson("/resources/{$this->resource->id}/landing-page", ['is_tombstone' => true])->assertUnprocessable();
    $this->postJson($this->endpoint, $this->payload)->assertOk();
    $this->putJson("/resources/{$this->resource->id}/landing-page", ['template' => 'default_gfz', 'activate_downloads' => true])->assertConflict();
    $this->deleteJson("/resources/{$this->resource->id}/landing-page")->assertUnprocessable();
    expect($this->user->can('delete', $this->resource->fresh()))->toBeFalse();
});

test('public tombstone retains metadata but removes downloads and portal discovery', function () {
    $file = LandingPageFile::create(['landing_page_id' => $this->page->id, 'url' => 'https://example.org/secondary.zip', 'position' => 0]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->get($this->page->public_url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('LandingPages/default_gfz')->where('landingPage.is_tombstone', true)
        ->where('landingPage.ftp_url', null)->where('landingPage.files', [])->where('landingPage.links', []));
    $this->get(route('landing-page.download.primary', $this->page))->assertGone();
    $this->get(route('landing-page.download.file', [$this->page, $file]))->assertGone();
    expect(app(PortalSearchService::class)->buildFilteredResourceQuery([])->whereKey($this->resource->id)->exists())->toBeFalse();
    expect(Resource::published()->whereKey($this->resource->id)->exists())->toBeFalse();
    $this->get(route('landing-page.metadata.datacite-json', ['doiPrefix' => $this->page->doi_prefix, 'slug' => $this->page->slug]))->assertOk();
});

test('resolved access metadata does not expose downloads on a tombstone', function (?AccessLevel $curatedLevel, AccessLevel $exportedLevel) {
    $this->resource->update(['access_level' => $curatedLevel]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();

    $metadata = app(LandingPageMachineMetadataService::class)->for($this->resource->fresh(), $this->page->fresh());
    $jsonLd = json_decode($metadata['jsonLdJson'], true, flags: JSON_THROW_ON_ERROR);
    expect($jsonLd['conditionsOfAccess'])->toBe('This resource is no longer available.')
        ->and($jsonLd['isAccessibleForFree'])->toBeFalse()
        ->and($jsonLd)->not->toHaveKey('distribution')
        ->and(collect($metadata['signpostingLinks'])->pluck('rel'))->not->toContain('item')
        ->and(collect($metadata['dublinCore'])->where('name', 'DC.accessRights')->pluck('content')->all())
        ->toBe([$exportedLevel->label(), $exportedLevel->coarUri()]);

    $this->get($this->page->public_url.'/metadata/datacite.json')->assertOk()
        ->assertJsonPath('data.attributes.rightsList.0.rightsUri', $exportedLevel->coarUri());
    $this->get($this->page->public_url.'/metadata/datacite.xml')->assertOk()
        ->assertSee('rightsURI="'.$exportedLevel->coarUri().'"', escape: false);

    expect($this->resource->fresh()->access_level)->toBe($curatedLevel);
    Http::assertSentCount(1);
})->with([
    'inferred metadata only' => [null, AccessLevel::METADATA_ONLY],
    'preserved curated access' => [AccessLevel::OPEN, AccessLevel::OPEN],
]);

test('sync hides findable DOIs and confirms both state and target URL', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(fn ($request) => Http::response(($this->remote)($request->method() === 'PUT' ? 'registered' : 'findable')));
    app(ResourceTombstoneSyncService::class)->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('succeeded');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['data']['attributes'] === ['url' => $this->page->public_url, 'event' => 'hide']);
});

test('sync failure keeps a public dead resource and can be retried', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response([], 403)]);
    app(ResourceTombstoneSyncService::class)->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('failed')
        ->and($this->resource->fresh()->publicStatus())->toBe('dead');
    $this->postJson($this->endpoint.'/retry-sync', ['revision' => 1])->assertOk()->assertJsonPath('tombstone.sync.status', 'pending');
});

test('temporary failures are durably scheduled and already accepted changes are reconciled', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response([], 503)]);
    $sync = app(ResourceTombstoneSyncService::class);
    $sync->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('pending')
        ->and(ResourceTombstoneTransition::first()->available_at)->not->toBeNull();
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $this->travel(2)->minutes();
    $sync->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('succeeded');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

test('an old activation job cannot hide a restored DOI', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $old = ResourceTombstoneTransition::first();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    Http::swap(new Factory);
    Http::fake();
    app(ResourceTombstoneSyncService::class)->sync($old->id);
    expect($old->fresh()->status)->toBe('superseded');
    Http::assertNothingSent();
});

test('stale metadata publish and URL repair payloads preserve the active tombstone', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $client = app(DataCiteMemberApiClient::class);
    $client->updateDoi($this->resource->doi, ['data' => ['type' => 'dois', 'attributes' => ['event' => 'publish', 'url' => 'https://example.org/stale', 'titles' => [['title' => 'Corrected metadata']]]]]);
    $client->updateLandingPageUrl($this->resource->doi, 'https://example.org/stale');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT' && ($request['data']['attributes']['event'] ?? null) === 'publish');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT' && $request['data']['attributes']['url'] !== $this->page->public_url);
});

test('a DOI without an ERNIE page gets a tombstone and requires a restore publication choice', function () {
    $this->page->delete();
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('restore_published');
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true, 'restore_published' => true])->assertOk()->assertJsonPath('publicstatus', 'published');
});

test('tombstone previews do not publish or contact DataCite and cannot be created by beginners', function () {
    $payload = ['template' => 'default_gfz', 'is_tombstone' => true, 'tombstone_reason' => 'retracted', 'tombstone_statement' => 'This resource has been retracted.'];
    $this->actingAs($this->user)->postJson("/resources/{$this->resource->id}/landing-page/preview", $payload)->assertCreated();
    $this->get("/resources/{$this->resource->id}/landing-page/preview")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('landingPage.is_tombstone', true)->where('landingPage.ftp_url', null)->where('isPreview', true));
    expect($this->resource->fresh()->publicStatus())->toBe('published')->and(ResourceTombstoneTransition::count())->toBe(0);
    Http::assertNothingSent();
    $this->user->update(['role' => UserRole::BEGINNER]);
    $this->postJson("/resources/{$this->resource->id}/landing-page/preview", $payload)->assertForbidden();
});

test('machine metadata retains the DOI and explanation without data access links', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $machine = app(LandingPageMachineMetadataService::class)->for($this->resource->fresh(), $this->page->fresh());
    $jsonLd = json_decode($machine['jsonLdJson'], true, flags: JSON_THROW_ON_ERROR);
    expect($jsonLd['identifier']['value'])->toBe('doi:'.$this->resource->doi)
        ->and($jsonLd['description'])->toContain($this->payload['statement'])
        ->and($jsonLd)->not->toHaveKey('distribution');
    expect(array_filter($machine['signpostingLinks'], fn (array $link) => $link['rel'] === 'item'))->toBe([]);
});

test('physical objects and software retain their type on public tombstones without content links', function (string $slug, string $expected): void {
    $type = ResourceType::firstOrCreate(['slug' => $slug], ['name' => ucwords(str_replace('-', ' ', $slug))]);
    $this->resource->update(['resource_type_id' => $type->id]);
    $this->page->links()->create(['url' => 'https://example.org/repository', 'kind' => 'repository', 'label' => 'Source', 'position' => 0]);
    $this->page->forceFill([
        'is_tombstone' => true,
        'tombstone_reason' => $this->payload['reason'],
        'tombstone_statement' => $this->payload['statement'],
        'tombstoned_at' => now(),
    ])->save();
    $response = $this->get($this->page->public_url)->assertOk();
    $machine = app(LandingPageMachineMetadataService::class)->for($this->resource->fresh(), $this->page->fresh());
    $jsonLd = json_decode($machine['jsonLdJson'], true, flags: JSON_THROW_ON_ERROR);

    expect(is_array($jsonLd['@type']) ? $jsonLd['@type'][0] : $jsonLd['@type'])->toBe($expected)
        ->and($jsonLd['@id'])->toBe('https://doi.org/'.$this->resource->doi)
        ->and($jsonLd['description'])->toContain($this->payload['statement'])
        ->and($response->headers->get('Link'))->toContain('<https://schema.org/'.$expected.'>; rel="type"')
        ->not->toContain('rel="item"')
        ->and($machine['jsonLdJson'])->not->toContain('https://example.org/repository')
        ->and($jsonLd)->not->toHaveKeys(['distribution', 'downloadUrl', 'associatedMedia']);
    if ($slug === 'physical-object') {
        $description = collect($jsonLd['subjectOf'])->firstWhere('@type', 'CreativeWork');
        expect($description['conditionsOfAccess'])->toContain('This resource is no longer available.')
            ->and($description)->not->toHaveKey('isAccessibleForFree');
    } else {
        expect($jsonLd['isAccessibleForFree'])->toBeFalse();
    }
})->with([['physical-object', 'Thing'], ['software', 'SoftwareSourceCode']]);

test('Dead is projected and can be filtered separately from Published', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->get('/resources?status[]=dead')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('resources', 1)->where('resources.0.publicstatus', 'dead'));
    $this->get('/resources?status[]=published')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('resources', 0));
    $this->getJson('/resources/filter-options')->assertOk()->assertJsonPath('statuses.5', 'dead');
});

test('activation preflight failures leave no local mutation or remote write', function () {
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response([], 503)]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('doi');
    expect($this->page->fresh()->is_tombstone)->toBeFalse()->and(ResourceTombstoneTransition::count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('sync updates only the URL of an already registered DOI', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(fn ($request) => Http::response(($this->remote)('registered', $request->method() === 'GET' ? 'https://example.org/old' : $this->page->public_url)));
    app(ResourceTombstoneSyncService::class)->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('succeeded');
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['data']['attributes'] === ['url' => $this->page->public_url]);
});

test('restoration republishes only a previously findable DOI', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    Http::swap(new Factory);
    Http::fake(fn ($request) => Http::response(($this->remote)($request->method() === 'GET' ? 'registered' : 'findable')));
    $transition = ResourceTombstoneTransition::latest('revision')->first();
    app(ResourceTombstoneSyncService::class)->sync($transition->id);
    expect($transition->fresh()->status)->toBe('succeeded');
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['data']['attributes']['event'] === 'publish');
});

test('multiple activation cycles preserve independent configuration snapshots', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    $this->page->refresh()->update(['ftp_url' => 'https://example.org/recovered.zip']);
    $this->postJson($this->endpoint, [...$this->payload, 'revision' => 2])->assertOk();
    $activations = ResourceTombstoneTransition::where('action', 'activate')->orderBy('revision')->get();
    expect($activations)->toHaveCount(2)
        ->and($activations[0]->snapshot['configuration']['ftp_url'])->toBe('https://example.org/data.zip')
        ->and($activations[1]->snapshot['configuration']['ftp_url'])->toBe('https://example.org/recovered.zip');
});

test('completed restoration of a registered DOI allows later intentional publication', function (string $status) {
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    $transition = ResourceTombstoneTransition::latest('id')->first();
    app(ResourceTombstoneSyncService::class)->sync($transition->id);
    expect($transition->fresh()->status)->toBe('succeeded')
        ->and($this->page->fresh()->is_tombstone)->toBeFalse();
    $transition->update(['status' => $status]);

    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('findable'))]);
    app(DataCiteMemberApiClient::class)->updateDoi($this->resource->doi, [
        'data' => ['type' => 'dois', 'id' => $this->resource->doi, 'attributes' => ['url' => 'https://example.org/published', 'event' => 'publish']],
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['data']['attributes']['event'] === 'publish'
        && $request['data']['attributes']['url'] === 'https://example.org/published');
})->with(['succeeded', 'superseded']);

test('unfinished restoration still protects the desired registered state and URL', function (string $status) {
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    $transition = ResourceTombstoneTransition::latest('id')->first();
    $transition->update(['status' => $status]);
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('findable'))]);
    app(DataCiteMemberApiClient::class)->updateDoi($this->resource->doi, [
        'data' => ['type' => 'dois', 'id' => $this->resource->doi, 'attributes' => ['url' => 'https://example.org/stale', 'event' => 'publish']],
    ]);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['data']['attributes']['event'] === 'hide'
        && $request['data']['attributes']['url'] === $transition->target_url);
})->with(['pending', 'running', 'failed']);

test('normal model updates cannot turn a tombstone into an external redirect', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    expect(fn () => $this->page->fresh()->update(['template' => 'external']))->toThrow(ValidationException::class);
});

test('recover dispatches due and expired jobs and leaves successful and failed work alone', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    app(ResourceTombstoneSyncService::class)->recover();
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class);
    $transition = ResourceTombstoneTransition::first();
    $transition->forceFill(['status' => 'running', 'updated_at' => now()->subMinutes(10)])->save();
    Queue::fake([SyncResourceTombstoneWithDataCiteJob::class]);
    app(ResourceTombstoneSyncService::class)->recover();
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class);
    $transition->update(['status' => 'succeeded']);
    Queue::fake([SyncResourceTombstoneWithDataCiteJob::class]);
    app(ResourceTombstoneSyncService::class)->recover();
    Queue::assertNothingPushed();
});

test('repeated recovery ticks enqueue only one job per pending transition', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $sync = app(ResourceTombstoneSyncService::class);
    for ($tick = 0; $tick < 15; $tick++) {
        $this->travel(1)->minutes();
        $sync->recover();
    }
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class, 1);
    expect(ResourceTombstoneTransition::first()->status)->toBe('pending');
});

test('recovery releases an abandoned worker lock once and keeps the recovered job unique', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $transition = ResourceTombstoneTransition::first();
    $transition->forceFill(['status' => 'running', 'updated_at' => now()->subMinutes(10)])->save();
    Queue::fake([SyncResourceTombstoneWithDataCiteJob::class]);
    $sync = app(ResourceTombstoneSyncService::class);
    $sync->recover();
    $sync->recover();
    expect($transition->fresh()->status)->toBe('pending');
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class, 1);
});

test('a failed queue push releases its unique lock so recovery can dispatch again', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $job = Queue::pushed(SyncResourceTombstoneWithDataCiteJob::class)->first();
    (new UniqueLock(app(Repository::class)))->release($job);
    Queue::fake([SyncResourceTombstoneWithDataCiteJob::class]);
    $dispatcher = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $sync = app(ResourceTombstoneSyncService::class);
    try {
        $sync->recover();
    } finally {
        Bus::swap($dispatcher);
    }
    $sync->recover();
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class, 1);
});

test('completion of a queued attempt permits the same transition to be retried', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $transition = ResourceTombstoneTransition::first();
    $job = Queue::pushed(SyncResourceTombstoneWithDataCiteJob::class)->first();
    $transition->update(['status' => 'failed']);
    // The queue worker releases this owned lock when the attempt finishes.
    (new UniqueLock(app(Repository::class)))->release($job);
    $this->postJson($this->endpoint.'/retry-sync', ['revision' => 1])->assertOk();
    Queue::assertPushed(SyncResourceTombstoneWithDataCiteJob::class, 2);
});

test('IGSNs cannot activate resource tombstones', function () {
    $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object', 'is_active' => true]);
    $this->resource->update(['identifier_type' => 'IGSN', 'resource_type_id' => $type->id]);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertUnprocessable();
    Http::assertNothingSent();
});

test('the editor exposes the Dead status and public tombstone while preventing DOI edits', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $url = '/editor?resourceId='.$this->resource->id;
    $token = $this->get($url)->assertOk()->inertiaProps('editorLoad.token');
    $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('editor')->where('publicStatus', 'dead')->where('canEditDoi', false)
        ->where('landingPage.is_tombstone', true)->where('landingPage.is_published', true)
        ->where('landingPage.public_url', $this->page->public_url));
});

test('beginners can read tombstone state but cannot mutate it', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $this->actingAs($beginner)->getJson($this->endpoint)->assertOk()
        ->assertJsonPath('tombstone.can_manage', false)
        ->assertJsonPath('tombstone.is_tombstone', true)
        ->assertJsonPath('tombstone.statement', $this->payload['statement']);
});

test('tombstones suppress data requests on the backend as well as downloads', function () {
    Mail::fake();
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->postJson($this->page->public_url.'/contact', [
        'sender_name' => 'Reader', 'sender_email' => 'reader@example.org',
        'message' => 'Please send the original dataset.', 'send_to_all' => true, 'data_request' => true,
    ])->assertGone();
    Mail::assertNothingSent();
    expect(ContactMessage::count())->toBe(0);
});

test('stale normal preview sessions render the active tombstone and omit saved data URLs', function () {
    $preview = $this->actingAs($this->user)->postJson("/resources/{$this->resource->id}/landing-page/preview", ['template' => 'default_gfz'])->assertCreated()->json('preview_url');
    $this->postJson($this->endpoint, $this->payload)->assertOk();
    $this->get($preview)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('LandingPages/default_gfz')->where('landingPage.is_tombstone', true)
        ->where('landingPage.tombstone_statement', $this->payload['statement'])
        ->where('landingPage.ftp_url', null)->where('landingPage.files', []));
});

test('retry rejects stale revisions and running work and is harmless after success', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->postJson($this->endpoint.'/retry-sync', ['revision' => 0])->assertConflict();
    $transition = ResourceTombstoneTransition::first();
    $transition->update(['status' => 'running']);
    $this->postJson($this->endpoint.'/retry-sync', ['revision' => 1])->assertConflict();
    $transition->update(['status' => 'succeeded', 'attempts' => 1]);
    $this->postJson($this->endpoint.'/retry-sync', ['revision' => 1])->assertOk()->assertJsonPath('tombstone.sync.status', 'succeeded');
    expect($transition->fresh()->attempts)->toBe(1);
});

test('backoff prevents duplicate jobs from retrying early and stops after five attempts', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response([], 503)]);
    $transition = ResourceTombstoneTransition::first();
    $sync = app(ResourceTombstoneSyncService::class);
    $sync->sync($transition->id);
    $sync->sync($transition->id);
    expect($transition->fresh()->attempts)->toBe(1);
    for ($attempt = 2; $attempt <= 5; $attempt++) {
        $this->travelTo($transition->fresh()->available_at->addSecond());
        $sync->sync($transition->id);
    }
    expect($transition->fresh()->status)->toBe('failed')->and($transition->fresh()->attempts)->toBe(5);
});

test('sync verifies DataCite ownership and response confirmation before marking success', function (bool $wrongOwner) {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    Http::swap(new Factory);
    Http::fake(function ($request) use ($wrongOwner) {
        $remote = ($this->remote)('findable');
        if ($wrongOwner) {
            $remote['data']['relationships']['client']['data']['id'] = 'another.repository';
        }

        return Http::response($remote);
    });
    app(ResourceTombstoneSyncService::class)->sync(ResourceTombstoneTransition::first()->id);
    expect(ResourceTombstoneTransition::first()->status)->toBe('pending');
    if ($wrongOwner) {
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }
})->with([true, false]);

test('DataCite jobs keep the activation environment even after settings change', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    config(['datacite.test_mode' => false]);
    Http::swap(new Factory);
    Http::fake(['*datacite.org/*' => Http::response(($this->remote)('registered'))]);
    $transition = ResourceTombstoneTransition::first();
    (new SyncResourceTombstoneWithDataCiteJob($transition->id))->handle(app(ResourceTombstoneSyncService::class));
    expect($transition->fresh()->status)->toBe('succeeded');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.test.datacite.org/'));
});

test('an active tombstone DOI cannot be replaced even by an admin', function () {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->user->update(['role' => UserRole::ADMIN]);
    expect($this->user->can('editDoi', $this->resource->fresh()))->toBeFalse();
});

test('portal counts facets and keyword suggestions exclude tombstones and return after restoration', function () {
    $subject = Subject::factory()->create(['resource_id' => $this->resource->id, 'value' => 'Tombstone-only keyword', 'subject_scheme' => null]);
    $portal = app(PortalSearchService::class);
    $keywords = app(KeywordSuggestionService::class);
    expect($portal->count([]))->toBe(1)
        ->and($keywords->getSuggestions())->toHaveCount(1);
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    // Run the same cache invalidation boundary that the committed observer schedules.
    app(PortalCacheInvalidationService::class)->flushPending();
    $keywords->invalidateCache();
    expect($portal->count([]))->toBe(0)
        ->and($portal->getResourceTypeFacets())->toBe([])
        ->and($keywords->getSuggestions())->toBe([]);
    $this->deleteJson($this->endpoint, ['revision' => 1, 'confirmed' => true])->assertOk();
    app(PortalCacheInvalidationService::class)->flushPending();
    $keywords->invalidateCache();
    expect($portal->count([]))->toBe(1)
        ->and($keywords->getSuggestions())->toHaveCount(1);
});

test('tombstone state polling stays local and does not imply activation eligibility', function () {
    $this->actingAs($this->user)->getJson($this->endpoint)->assertOk()
        ->assertJsonMissingPath('tombstone.can_activate')->assertJsonMissingPath('activation_eligibility');
    Http::assertNothingSent();
});

test('activation eligibility verifies registration with the authenticated effective DataCite repository', function (bool $testMode, UserRole $role, string $state) {
    $this->user->update(['role' => $role]);
    config([
        'datacite.test_mode' => $testMode,
        'datacite.production.client_id' => 'production.repository',
        'datacite.production.username' => 'production.repository',
        'datacite.production.password' => 'production-password',
        'datacite.production.endpoint' => 'https://api.datacite.org',
    ]);
    $remote = ($this->remote)($state);
    $remote['data']['relationships']['client']['data']['id'] = $testMode ? 'TEST.REPOSITORY' : 'PRODUCTION.REPOSITORY';
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($remote)]);
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', 'eligible')->assertJsonPath('activation_eligibility.reason', null);
    $host = $testMode ? 'api.test.datacite.org' : 'api.datacite.org';
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), $host) && $request->hasHeader('Authorization'));
    Http::assertSentCount(1);
    expect($this->page->fresh()->is_tombstone)->toBeFalse()->and(ResourceTombstoneTransition::count())->toBe(0);
})->with([true, false])->with([UserRole::CURATOR, UserRole::GROUP_LEADER, UserRole::ADMIN])->with(['registered', 'findable']);

test('registration eligibility is independent of the local workflow status', function (string $status) {
    $this->page->update(['is_published' => $status === 'published']);
    $this->resource->update(['access_level' => $status === 'embargo' ? AccessLevel::EMBARGOED : AccessLevel::OPEN]);
    $this->resource->rights()->attach(Right::factory()->create()->id);
    Description::factory()->create(['resource_id' => $this->resource->id]);
    if ($status === 'draft' || $status === 'review') {
        $this->resource->update(['workflow_status_override' => ResourceWorkflowStatus::from($status)]);
    }
    if ($status === 'embargo') {
        $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
        $this->resource->dates()->create(['date_type_id' => $type->id, 'date_value' => now()->addYear()->toDateString()]);
    }
    if ($status === 'curation') {
        $this->page->delete();
    }
    expect($this->resource->fresh()->load(['titles.titleType', 'descriptions.descriptionType'])->publicStatus())->toBe($status);
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', 'eligible');
})->with(['draft', 'review', 'curation', 'embargo', 'published']);

test('ineligible local resources do not trigger remote registration checks', function (string $case, string $reason) {
    if ($case === 'doi') {
        $this->resource->update(['doi' => null]);
    } elseif ($case === 'igsn') {
        $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object', 'is_active' => true]);
        $this->resource->update(['resource_type_id' => $type->id]);
    } else {
        $this->user->update(['role' => UserRole::BEGINNER]);
    }
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', 'ineligible')->assertJsonPath('activation_eligibility.reason', $reason);
    Http::assertNothingSent();
})->with([['doi', 'missing_doi'], ['igsn', 'igsn'], ['beginner', 'forbidden']]);

test('active tombstones remain readable without a remote registration check', function (UserRole $role) {
    $this->actingAs($this->user)->postJson($this->endpoint, $this->payload)->assertOk();
    $this->user->update(['role' => $role]);
    Http::swap(new Factory);
    Http::fake(['*' => Http::failedConnection()]);
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('tombstone.is_tombstone', true)
        ->assertJsonPath('tombstone.can_manage', $role !== UserRole::BEGINNER);
    Http::assertNothingSent();
})->with([UserRole::CURATOR, UserRole::BEGINNER]);

test('remote eligibility distinguishes ineligible registrations from unavailable verification', function (string $case, string $status, string $reason) {
    $remote = ($this->remote)();
    $httpStatus = 200;
    match ($case) {
        'draft' => $remote['data']['attributes']['state'] = 'draft',
        'owner' => $remote['data']['relationships']['client']['data']['id'] = 'other.repository',
        'url' => $remote['data']['attributes']['url'] = '  ',
        'malformed' => $remote = ['data' => []],
        'missing_owner' => $remote['data']['relationships'] = [],
        default => $httpStatus = (int) $case,
    };
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($remote, $httpStatus)]);
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', $status)->assertJsonPath('activation_eligibility.reason', $reason);
    expect($this->page->fresh()->is_tombstone)->toBeFalse();
})->with([
    ['draft', 'ineligible', 'not_registered'], ['404', 'ineligible', 'not_registered'],
    ['owner', 'ineligible', 'foreign_repository'], ['url', 'ineligible', 'missing_target_url'],
    ['malformed', 'unavailable', 'verification_unavailable'], ['missing_owner', 'unavailable', 'verification_unavailable'],
    ['401', 'unavailable', 'verification_unavailable'], ['403', 'unavailable', 'verification_unavailable'],
    ['429', 'unavailable', 'verification_unavailable'], ['503', 'unavailable', 'verification_unavailable'],
]);

test('registration verification handles connection failure missing configuration and limiter cooldown', function (string $case) {
    if ($case === 'configuration') {
        config(['datacite.test.client_id' => null]);
    } elseif ($case === 'cooldown') {
        app(DataCiteRequestLimiter::class)->imposeCooldown(60);
    } else {
        Http::swap(new Factory);
        Http::fake(['*' => Http::failedConnection()]);
    }
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', 'unavailable');
    if ($case === 'cooldown') {
        Http::assertNothingSent();
    }
})->with(['connection', 'configuration', 'cooldown']);

test('activation rechecks the remote state after a positive eligibility response', function () {
    $this->actingAs($this->user)->getJson($this->endpoint.'?include_eligibility=1')->assertOk()
        ->assertJsonPath('activation_eligibility.status', 'eligible');
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(($this->remote)('draft'))]);
    $this->postJson($this->endpoint, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('doi');
    Http::assertSentCount(1);
    expect($this->page->fresh()->is_tombstone)->toBeFalse()->and(ResourceTombstoneTransition::count())->toBe(0);
});
