<?php

declare(strict_types=1);

use App\Exceptions\DataCiteVerificationException;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataCiteSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'datacite.test_mode' => true, 'datacite.test.username' => 'TEST.REPO',
        'datacite.test.password' => 'test', 'datacite.test.client_id' => 'test.repo',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
    ]);
    app(DataCiteRequestLimiter::class)->clear();
    Http::preventStrayRequests();
    $this->resource = Resource::factory()->create(['doi' => '10.83279/review']);
    $this->page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'is_published' => true]);
    $this->record = fn (string $state, string $owner = 'test.repo', string $doi = '10.83279/review'): array => [
        'data' => ['id' => $doi, 'type' => 'dois', 'attributes' => ['doi' => $doi, 'state' => $state, 'url' => $this->page->public_url],
            'relationships' => ['client' => ['data' => ['id' => $owner, 'type' => 'clients']]]],
    ];
});

it('never writes a missing or draft identifier', function (string $state): void {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response($state === 'missing' ? [] : ($this->record)($state), $state === 'missing' ? 404 : 200)
        : throw new RuntimeException('Unexpected write'));
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))
        ->toThrow(DataCiteVerificationException::class, 'Explicit DOI');
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with(['missing', 'draft']);

it('preserves registered and findable state without a lifecycle event', function (string $state): void {
    Http::fake(fn () => Http::response(($this->record)($state)));
    $result = app(DataCiteRegistrationService::class)->updateMetadata($this->resource);
    expect($result['data']['attributes']['state'])->toBe($state);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && ! array_key_exists('event', $request['data']['attributes'])
        && ! array_key_exists('state', $request['data']['attributes']));
})->with(['registered', 'findable']);

it('blocks unpublished pages before any remote request and retains local saves', function (): void {
    $this->page->update(['is_published' => false]);
    $this->resource->update(['version' => '2.0']);
    Http::fake();
    $result = app(DataCiteSyncService::class)->syncIfRegistered($this->resource->fresh());
    expect($result->hasFailed())->toBeTrue()
        ->and($result->reason)->toBe('landing_page_not_published')
        ->and($result->retryable)->toBeFalse()
        ->and($this->resource->fresh()->version)->toBe('2.0');
    Http::assertNothingSent();
});

it('fails closed when registration cannot be verified', function (int $status): void {
    Http::fake(fn () => Http::response([], $status));
    $result = app(DataCiteSyncService::class)->syncIfRegistered($this->resource);
    expect($result->hasFailed())->toBeTrue();
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PUT', 'POST'], true));
})->with([401, 403, 429, 500, 503]);

it('rejects mismatching or malformed remote evidence', function (string $case): void {
    $record = ($this->record)('findable');
    if ($case === 'owner') {
        $record['data']['relationships']['client']['data']['id'] = 'foreign.repo';
    }
    if ($case === 'doi') {
        $record['data']['id'] = '10.83279/another';
    }
    if ($case === 'state') {
        $record['data']['attributes']['state'] = 'unknown';
    }
    if ($case === 'missing-owner') {
        unset($record['data']['relationships']);
    }
    Http::fake(fn () => Http::response($case === 'json' ? 'invalid JSON' : $record));
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))->toThrow(DataCiteVerificationException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with(['owner', 'doi', 'state', 'missing-owner', 'json']);

it('guards direct URL and schema writes as well as full metadata', function (string $operation): void {
    Http::fake(fn () => Http::response(($this->record)('draft')));
    $client = app(DataCiteMemberApiClient::class);
    expect(fn () => $operation === 'url'
        ? $client->updateLandingPageUrl('10.83279/review', 'https://example.org/approved')
        : $client->updateDoi('10.83279/review', ['data' => ['type' => 'dois', 'attributes' => ['schemaVersion' => 'http://datacite.org/schema/kernel-4']]]))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with(['url', 'schema']);

it('rejects a visibility event on a normal direct update', function (): void {
    Http::fake();
    expect(fn () => app(DataCiteMemberApiClient::class)->updateDoi('10.83279/review', [
        'data' => ['attributes' => ['event' => 'publish']],
    ]))->toThrow(DataCiteVerificationException::class, 'cannot change DOI visibility');
    Http::assertNothingSent();
});

it('rechecks local publication after the verification GET', function (): void {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            $this->page->update(['is_published' => false]);
        }

        return Http::response(($this->record)('findable'));
    });
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))->toThrow(DataCiteVerificationException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('verifies again before a retry and never promotes a newly observed draft', function (): void {
    $reads = 0;
    Http::fake(function (Request $request) use (&$reads) {
        return $request->method() === 'GET'
            ? Http::response(($this->record)(++$reads === 1 ? 'registered' : 'draft'))
            : Http::response([], 503);
    });
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))->toThrow(DataCiteVerificationException::class);
    expect($reads)->toBe(2);
    expect(Http::recorded(fn (Request $request) => $request->method() === 'PUT'))->toHaveCount(1);
});

it('reports an unexpected remote state without sending a corrective event', function (): void {
    Http::fake(fn (Request $request) => Http::response(($this->record)($request->method() === 'GET' ? 'registered' : 'findable')));
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))->toThrow(DataCiteVerificationException::class, 'expected DOI state');
    Http::assertSentCount(2);
});

it('keeps legacy and explicit update routes closed for review metadata even with force', function (string $route): void {
    $this->page->update(['is_published' => false]);
    Http::fake();
    $this->actingAs(User::factory()->curator()->create())
        ->postJson(route($route, $this->resource), ['prefix' => '10.83279', 'force' => true])
        ->assertStatus(422)->assertJsonPath('error', 'landing_page_not_published');
    Http::assertNothingSent();
})->with(['resources.register-doi', 'resources.update-datacite-metadata']);

it('never creates through a legacy request with a prefix', function (): void {
    $this->resource->update(['doi' => null]);
    Http::fake();
    $this->actingAs(User::factory()->curator()->create())
        ->postJson(route('resources.register-doi', $this->resource), ['prefix' => '10.83279'])
        ->assertStatus(422);
    expect($this->resource->fresh()->doi)->toBeNull();
    Http::assertNothingSent();
});

it('requires separate publication confirmation that force cannot replace', function (): void {
    Http::fake();
    $this->actingAs(User::factory()->curator()->create())
        ->postJson(route('resources.publish-doi', $this->resource), ['force' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm_publication');
    Http::assertNothingSent();
});

it('returns read-only action evidence without promoting a draft', function (): void {
    Http::fake(fn () => Http::response(($this->record)('draft')));
    $this->actingAs(User::factory()->curator()->create())
        ->getJson(route('resources.datacite-status', $this->resource))
        ->assertOk()->assertJsonPath('state', 'draft')
        ->assertJsonPath('can_update', false)->assertJsonPath('can_publish', true);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('only offers publication when a non-tombstone landing page exists', function (string $pageState, bool $hasDoi): void {
    if (! $hasDoi) {
        $this->resource->update(['doi' => null]);
    }
    if ($pageState === 'missing') {
        $this->page->delete();
    } else {
        $this->page->forceFill([
            'is_published' => $pageState !== 'unpublished',
            'is_tombstone' => $pageState === 'tombstone',
        ])->save();
    }
    Http::fake(fn () => Http::response(($this->record)('draft')));

    $this->actingAs(User::factory()->curator()->create())
        ->getJson(route('resources.datacite-status', $this->resource))
        ->assertOk()
        ->assertJsonPath('state', $hasDoi ? 'draft' : 'unassigned')
        ->assertJsonPath('landing_page_published', in_array($pageState, ['published', 'tombstone'], true))
        ->assertJsonPath('can_update', false)
        ->assertJsonPath('can_publish', in_array($pageState, ['unpublished', 'published'], true));

    Http::assertSentCount($hasDoi ? 1 : 0);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with(['missing', 'unpublished', 'published', 'tombstone'])->with([false, true]);

it('fails before any request when the repository client ID is missing', function (): void {
    config(['datacite.test.client_id' => '']);
    Http::fake();
    $result = app(DataCiteSyncService::class)->syncIfRegistered($this->resource);
    expect($result->hasFailed())->toBeTrue()->and($result->errorMessage)->toContain('DATACITE_TEST_CLIENT_ID');
    Http::assertNothingSent();
});

it('does not reuse production registration evidence for a beginner', function (): void {
    config(['datacite.test_mode' => false, 'datacite.production.username' => 'PRODUCTION.REPO', 'datacite.production.password' => 'test']);
    $this->actingAs(User::factory()->beginner()->create());
    Http::fake(fn (Request $request) => Http::response([], 404));
    $result = app(DataCiteSyncService::class)->syncIfRegistered($this->resource);
    expect($result->reason)->toBe('doi_registration_required');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.test.datacite.org/'));
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || str_starts_with($request->url(), 'https://api.datacite.org/'));
});

it('keeps local changes on a verification connection failure', function (): void {
    $this->resource->update(['version' => 'saved-locally']);
    Http::fake(fn () => Http::failedConnection());
    $result = app(DataCiteSyncService::class)->syncIfRegistered($this->resource);
    expect($result->hasFailed())->toBeTrue()->and($result->retryable)->toBeTrue()
        ->and($this->resource->fresh()->version)->toBe('saved-locally');
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});
