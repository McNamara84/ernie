<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteRequestLimiter;
use App\Services\InteractiveApiRequestContextService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Cache::flush();
    config([
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-only',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'datacite.landing_page_url_update.requests_per_window' => 10,
        'datacite.interactive_reserved_requests' => 1,
    ]);
    $this->actor = User::factory()->groupLeader()->create();
    $this->resource = Resource::factory()->withDoi('10.5880/editor-isolation')->create();
    LandingPage::factory()->for($this->resource)->withDoi((string) $this->resource->doi)->published()->create();
    $creator = ResourceCreator::factory()->create(['resource_id' => $this->resource->id]);
    $creator->affiliations()->create([
        'name' => 'Example University',
        'identifier' => 'https://ror.org/02be6w209',
        'identifier_scheme' => 'ROR',
        'scheme_uri' => 'https://ror.org/',
    ]);
    // This test concerns DataCite capacity; ORCID's contract is tested separately.
    $creator->creatorable->forceFill(['name_identifier' => null])->save();
});

it('updates ROR metadata as group leader after background work exhausts its share', function () {
    $limiter = app(DataCiteRequestLimiter::class);
    for ($index = 0; $index < 9; $index++) {
        expect($limiter->reserveSlot())->toBe(0);
    }
    expect($limiter->reserveSlot())->toBeGreaterThan(0);
    Http::fake(['https://api.test.datacite.org/*' => Http::response(['data' => ['id' => $this->resource->doi]], 200)]);

    $this->actingAs($this->actor)->postJson(route('resources.register-doi', $this->resource), ['prefix' => ''])
        ->assertOk()->assertJson(['success' => true, 'updated' => true]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['data']['attributes']['creators'][0]['affiliation'][0]['affiliationIdentifier'] === 'https://ror.org/02be6w209');
    expect(app(InteractiveApiRequestContextService::class)->isInteractive())->toBeFalse();
});

it('reports real provider cooldown without waiting or losing saved affiliations', function () {
    app(DataCiteRequestLimiter::class)->imposeCooldown(300);
    Http::fake();
    $this->actingAs($this->actor)->postJson(route('resources.register-doi', $this->resource), ['prefix' => ''])
        ->assertStatus(503)->assertHeader('Retry-After')
        ->assertJson(['error' => 'datacite_temporarily_busy']);
    Http::assertNothingSent();
    expect($this->resource->fresh()->creators->first()->affiliations->first()->identifier)->toBe('https://ror.org/02be6w209')
        ->and(app(InteractiveApiRequestContextService::class)->isInteractive())->toBeFalse();
});

it('keeps saved metadata when another writer still owns the DOI lock', function () {
    $service = Mockery::mock(DataCiteRegistrationService::class);
    $service->shouldReceive('updateMetadata')->once()->andThrow(new LockTimeoutException);
    app()->instance(DataCiteRegistrationService::class, $service);
    $this->actingAs($this->actor)->postJson(route('resources.register-doi', $this->resource), ['prefix' => ''])
        ->assertStatus(503)->assertHeader('Retry-After', '2')
        ->assertJson(['error' => 'doi_update_in_progress']);
    expect($this->resource->fresh()->creators->first()->affiliations->first()->identifier)->toBe('https://ror.org/02be6w209')
        ->and(app(InteractiveApiRequestContextService::class)->isInteractive())->toBeFalse();
});
