<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function workflowEmbargo(string $date): Resource
{
    $resource = Resource::factory()->create(['doi' => null, 'access_level' => AccessLevel::EMBARGOED]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Embargoed dataset']);
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

test('a curator cannot publish or redirect an embargo preview directly', function (): void {
    $user = User::factory()->curator()->create();
    $resource = workflowEmbargo('2027-01-01');
    $resource->created_by_user_id = $user->id;
    $resource->save();
    $this->actingAs($user)
        ->postJson("/resources/{$resource->id}/landing-page", ['template' => 'default_gfz', 'status' => 'published'])
        ->assertStatus(422);
    $this->actingAs($user)
        ->postJson("/resources/{$resource->id}/landing-page", ['template' => 'default_gfz', 'status' => 'draft'])
        ->assertCreated();
    $this->actingAs($user)
        ->putJson("/resources/{$resource->id}/landing-page", ['template' => 'default_gfz', 'status' => 'published'])
        ->assertStatus(422);
    expect($resource->fresh()->landingPage->is_published)->toBeFalse();
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

test('the resource list filters Embargo and exposes the tokenized preview to a beginner editor', function (): void {
    $resource = workflowEmbargo('2027-01-01');
    $landingPage = LandingPage::factory()->draft()->create([
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
            ->where('resources.0.landingPage.preview_url', $landingPage->preview_url));
});

test('new DOI and IGSN HTTP registration paths block before the embargo date', function (): void {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
    ]);
    Http::fake();
    $user = User::factory()->curator()->create();
    $resource = workflowEmbargo('2099-01-01');
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);

    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Invalid request');
    $this->actingAs($user)
        ->postJson('/resources/batch-register', ['ids' => [$resource->id], 'prefix' => '10.83279'])
        ->assertStatus(207)
        ->assertJsonPath('failed.0.id', $resource->id);

    $igsn = workflowEmbargo('2099-01-01');
    $igsn->doi = '10.83279/EMBARGO-IGSN';
    $igsn->save();
    IgsnMetadata::create(['resource_id' => $igsn->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    LandingPage::factory()->draft()->create(['resource_id' => $igsn->id, 'doi_prefix' => null]);
    $this->actingAs($user)
        ->postJson("/igsns/{$igsn->id}/register")
        ->assertStatus(422);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), 'datacite.org'));
    expect($resource->fresh()->doi)->toBeNull()
        ->and($resource->fresh()->landingPage->is_published)->toBeFalse()
        ->and($igsn->fresh()->landingPage->is_published)->toBeFalse();
});

test('manual DOI registration on the Available day publishes with Open access and a stable redirect', function (): void {
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
    Http::fake(fn (Request $request) => Http::response([
        'data' => ['id' => '10.83279/RELEASED', 'type' => 'dois'],
    ], $request->method() === 'POST' ? 201 : 200));

    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/RELEASED');

    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->doi)->toBe('10.83279/RELEASED')
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    $this->get('/datasets/'.$resource->id)
        ->assertRedirect($landingPage->fresh()->public_url);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['data']['attributes']['url'] === url('/datasets/'.$resource->id));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data()['data']['attributes']['url'] === $landingPage->fresh()->public_url);
});

test('batch DOI registration releases a due embargo only after DataCite accepts it', function (): void {
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
    $landingPage = LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $user = User::factory()->curator()->create();
    Http::fake(fn (Request $request) => Http::response([
        'data' => ['id' => '10.83279/BATCH-RELEASED', 'type' => 'dois'],
    ], $request->method() === 'POST' ? 201 : 200));

    $this->actingAs($user)
        ->postJson('/resources/batch-register', ['ids' => [$resource->id], 'prefix' => '10.83279'])
        ->assertOk()
        ->assertJsonPath('success.0.doi', '10.83279/BATCH-RELEASED');

    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->doi)->toBe('10.83279/BATCH-RELEASED')
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['data']['attributes']['url'] === url('/datasets/'.$resource->id));
});

test('manual IGSN registration releases a due embargo with Open access', function (): void {
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
    Http::fake(fn (Request $request) => Http::response([
        'data' => ['id' => '10.83279/IGSN-RELEASED', 'type' => 'dois'],
    ], $request->method() === 'POST' ? 201 : 200));

    $this->actingAs($user)
        ->postJson("/igsns/{$resource->id}/register")
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/IGSN-RELEASED');

    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($resource->fresh()->publication_year)->toBe(2027)
        ->and($metadata->fresh()->isRegistered())->toBeTrue()
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['data']['attributes']['url'] === url('/datasets/'.$resource->id)
        && collect($request->data()['data']['attributes']['rightsList'])
            ->contains('rightsIdentifier', AccessLevel::OPEN->coarIdentifier()));
});

test('a timed-out embargo DOI create is reconciled without a second POST', function (): void {
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
    $landingPage = LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $user = User::factory()->curator()->create();
    $postCount = 0;
    Http::fake(function (Request $request) use (&$postCount, $resource) {
        if ($request->method() === 'POST') {
            $postCount++;

            return Http::response(['errors' => [['title' => 'Gateway timeout']]], 500);
        }
        if ($request->method() === 'GET') {
            return Http::response(['data' => [[
                'id' => '10.83279/RECOVERED',
                'attributes' => [
                    'url' => url('/datasets/'.$resource->id),
                    'state' => 'findable',
                    'rightsList' => [['rightsIdentifier' => AccessLevel::OPEN->coarIdentifier()]],
                ],
            ]]], 200);
        }

        return Http::response(['data' => ['id' => '10.83279/RECOVERED']], 200);
    });

    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertStatus(500);
    expect($resource->fresh()->embargo_registration_started_at)->not->toBeNull();
    expect($resource->fresh()->landingPage->is_published)->toBeFalse();

    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/RECOVERED');

    expect($postCount)->toBe(1)
        ->and($resource->fresh()->embargo_registration_started_at)->toBeNull()
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($landingPage->fresh()->is_published)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'query=url%3A%22'));
});

test('an unresolved embargo DOI create stays private and is never posted twice', function (): void {
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
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $user = User::factory()->curator()->create();
    $postCount = 0;
    Http::fake(function (Request $request) use (&$postCount) {
        if ($request->method() === 'POST') {
            $postCount++;

            return Http::response(['errors' => [['title' => 'Gateway timeout']]], 500);
        }

        return Http::response(['data' => []], 200);
    });

    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertStatus(500);
    $this->actingAs($user)
        ->postJson(route('resources.register-doi', $resource), ['prefix' => '10.83279'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Registration failed');

    expect($postCount)->toBe(1)
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::EMBARGOED)
        ->and($resource->fresh()->embargo_registration_started_at)->not->toBeNull()
        ->and($resource->fresh()->landingPage->is_published)->toBeFalse();
});

test('a timed-out embargo IGSN create is reconciled by identifier', function (): void {
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
    $resource->doi = '10.83279/RECOVERED-IGSN';
    $resource->save();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_UPLOADED]);
    $landingPage = LandingPage::factory()->draft()->create(['resource_id' => $resource->id, 'doi_prefix' => null]);
    $user = User::factory()->curator()->create();
    $postCount = 0;
    Http::fake(function (Request $request) use (&$postCount, $resource) {
        if ($request->method() === 'POST') {
            $postCount++;

            return Http::response(['errors' => [['title' => 'Gateway timeout']]], 500);
        }
        if ($request->method() === 'GET') {
            return Http::response(['data' => [
                'id' => '10.83279/RECOVERED-IGSN',
                'attributes' => [
                    'url' => url('/datasets/'.$resource->id),
                    'state' => 'findable',
                    'rightsList' => [['rightsIdentifier' => AccessLevel::OPEN->coarIdentifier()]],
                ],
            ]], 200);
        }

        return Http::response(['data' => ['id' => '10.83279/RECOVERED-IGSN']], 200);
    });

    $this->actingAs($user)->postJson("/igsns/{$resource->id}/register")->assertStatus(500);
    expect($resource->fresh()->embargo_registration_started_at)->not->toBeNull();
    $this->actingAs($user)->postJson("/igsns/{$resource->id}/register")
        ->assertOk()
        ->assertJsonPath('doi', '10.83279/RECOVERED-IGSN');

    expect($postCount)->toBe(1)
        ->and($resource->fresh()->embargo_registration_started_at)->toBeNull()
        ->and($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($metadata->fresh()->isRegistered())->toBeTrue()
        ->and($landingPage->fresh()->is_published)->toBeTrue();
});
