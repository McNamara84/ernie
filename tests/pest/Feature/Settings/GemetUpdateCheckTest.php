<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Settings\ThesaurusSettingsController;
use App\Models\ThesaurusSetting;
use App\Models\User;
use App\Services\GemetApiService;
use App\Services\ThesaurusStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GemetUpdateCheckClock;
use Tests\Support\GemetUpdateCheckFixture as Gemet;

covers(ThesaurusSettingsController::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake();
    $this->gemet = new GemetUpdateCheckClock;
    $this->app->instance(GemetApiService::class, $this->gemet);
    Http::preventStrayRequests();
});

it('recovers the reported group 96 connection timeout without refetching successful groups', function (): void {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $uri = $request['concept_uri'] ?? $request['thesaurus_uri'];
        $calls[$uri] = ($calls[$uri] ?? 0) + 1;
        if ($uri === Gemet::GROUP.'96' && $calls[$uri] === 1) {
            throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds');
        }

        return Gemet::response($request);
    });

    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/gemet/check')
        ->assertOk()
        ->assertJson([
            'type' => 'gemet', 'localCount' => 0, 'remoteCount' => 5,
            'updateAvailable' => true, 'lastUpdated' => null,
        ]);

    expect($calls[Gemet::GROUP.'96'])->toBe(2)
        ->and($calls[Gemet::GROUP.'97'])->toBe(1);
});

it('returns a friendly JSON 503 for persistent failures at any GEMET phase', function (string $uri): void {
    $attempts = 0;
    Http::fake(function (Request $request) use ($uri, &$attempts) {
        if (($request['concept_uri'] ?? $request['thesaurus_uri']) === $uri) {
            $attempts++;
            throw new ConnectionException('cURL error 28: timed out');
        }

        return Gemet::response($request);
    });

    // Match the browser's POST without an Accept header; the controller must
    // produce JSON itself even for failures in the navigation requests.
    $this->actingAs(User::factory()->admin()->create())
        ->post('/thesauri/gemet/check')
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['error' => 'Failed to check remote status: GEMET could not be reached. Please try again later.']);
    expect($attempts)->toBe(2);
})->with([
    'supergroups' => 'http://www.eionet.europa.eu/gemet/supergroup/',
    'groups' => Gemet::GROUP,
    'members' => Gemet::GROUP.'96',
]);

it('returns a JSON 503 when the common budget expires', function (): void {
    Http::fake(function (Request $request) {
        $this->gemet->time = 90;

        return Gemet::response($request);
    });

    $this->actingAs(User::factory()->admin()->create())
        ->post('/thesauri/gemet/check')
        ->assertStatus(503)
        ->assertJsonPath('error', 'Failed to check remote status: GEMET update check exceeded its time limit. Please try again later.');
    Http::assertSentCount(1);
});

it('caches complete counts for one hour and honors vocabulary cache invalidation', function (): void {
    Http::fake(fn (Request $request) => Gemet::response($request));
    $setting = ThesaurusSetting::where('type', 'gemet')->firstOrFail();
    $status = app(ThesaurusStatusService::class);

    expect($status->getRemoteConceptCount($setting))->toBe(5)
        ->and($status->getRemoteConceptCount($setting))->toBe(5);
    Http::assertSentCount(4);

    $this->travel(3599)->seconds();
    expect($status->getRemoteConceptCount($setting))->toBe(5);
    Http::assertSentCount(4);
    $this->travel(2)->seconds();
    expect($status->getRemoteConceptCount($setting))->toBe(5);
    Http::assertSentCount(8);

    $this->artisan('cache:clear-app', ['category' => 'vocabularies'])->assertSuccessful();
    expect($status->getRemoteConceptCount($setting))->toBe(5);
    Http::assertSentCount(12);
});

it('does not cache a failed or partial count and allows the next check to succeed', function (bool $invalid): void {
    $failed = true;
    Http::fake(function (Request $request) use (&$failed, $invalid) {
        if ($failed && ($request['concept_uri'] ?? null) === Gemet::GROUP.'97') {
            if ($invalid) {
                return Http::response('<html>upstream error</html>', 200);
            }
            throw new ConnectionException('timeout');
        }

        return Gemet::response($request);
    });
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->postJson('/thesauri/gemet/check')->assertStatus(503);
    $failed = false;
    $this->postJson('/thesauri/gemet/check')->assertOk()->assertJsonPath('remoteCount', 5);
    $sent = Http::recorded()->count();
    $this->postJson('/thesauri/gemet/check')->assertOk()->assertJsonPath('remoteCount', 5);
    expect(Http::recorded()->count())->toBe($sent);
})->with(['connection error' => false, 'invalid payload' => true]);

it('keeps local vocabulary data and update queues intact during checks', function (): void {
    Queue::fake();
    $data = json_encode(['lastUpdated' => '2026-10-01T00:00:00Z', 'data' => [['text' => 'local']]]);
    Storage::put('gemet-thesaurus.json', $data);
    Http::fake(fn (Request $request) => Gemet::response($request));

    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/gemet/check')->assertOk()->assertJson([
            'localCount' => 1, 'remoteCount' => 5,
            'lastUpdated' => '2026-10-01T00:00:00Z', 'updateAvailable' => true,
        ]);
    expect(Storage::get('gemet-thesaurus.json'))->toBe($data);
    Queue::assertNothingPushed();
});

it('allows group leaders to check GEMET', function (): void {
    Http::fake(fn (Request $request) => Gemet::response($request));
    $this->actingAs(User::factory()->create(['role' => UserRole::GROUP_LEADER]))
        ->postJson('/thesauri/gemet/check')->assertOk();
});

it('rejects unauthorized GEMET checks before making external requests', function (?UserRole $role): void {
    if ($role !== null) {
        $this->actingAs(User::factory()->create(['role' => $role]));
    }
    Http::fake();
    $this->postJson('/thesauri/gemet/check')->assertStatus($role === null ? 401 : 403);
    Http::assertNothingSent();
})->with([null, UserRole::CURATOR]);
