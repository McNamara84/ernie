<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteServiceInterface;
use App\Services\FakeDataCiteRegistrationService;
use App\Services\OrcidService;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/**
 * Feature tests for the DOI registration ORCID preflight (issue #610).
 *
 * These tests exercise the full controller path (policy → request → service →
 * OrcidPreflightValidator → DataCite) and verify the three documented
 * outcomes: happy path (200), hard block (422), and warning override (409).
 */
beforeEach(function () {
    withoutVite();

    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.prefixes' => ['10.5880'],
    ]);

    $this->user = User::factory()->create();
    $this->resource = Resource::factory()->create([
        'doi' => null,
        'created_by_user_id' => $this->user->id,
        'updated_by_user_id' => $this->user->id,
    ]);
    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'is_published' => false,
    ]);

    // Ensure DataCite calls go through the fake implementation so we never
    // touch the real API during preflight tests.
    app()->bind(DataCiteRegistrationService::class, fn () => new FakeDataCiteRegistrationService);
    app()->bind(DataCiteServiceInterface::class, fn () => new FakeDataCiteRegistrationService);
});

/** Attaches one creator with the given ORCID + scheme to the resource. */
function attachCreatorWithOrcid(Resource $resource, string $orcid, ?string $scheme = 'ORCID'): Person
{
    $person = Person::factory()->create([
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => $orcid,
        'name_identifier_scheme' => $scheme,
        'orcid_verified_at' => null,
    ]);
    ResourceCreator::factory()
        ->forPerson($person)
        ->position(0)
        ->create(['resource_id' => $resource->id]);

    return $person;
}

test('registerDoi blocks with 422 when a creator ORCID is not found', function () {
    actingAs($this->user);
    $person = attachCreatorWithOrcid($this->resource, '0000-0002-1825-0097');

    $this->mock(OrcidService::class, function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->once()
            ->andReturn([
                'valid' => false,
                'exists' => false,
                'message' => 'Not found',
                'errorType' => 'not_found',
            ]);
    });

    $response = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertStatus(422);
    $response->assertJson([
        'error' => 'orcid_validation_failed',
    ]);
    expect($response->json('invalid'))->toHaveCount(1);
    expect($response->json('invalid.0.reason'))->toBe('not_found');
    expect($this->resource->fresh()->doi)->toBeNull();
    expect($person->fresh()->orcid_verified_at)->toBeNull();
});

test('registerDoi returns 409 warning for transient ORCID failures and succeeds when replayed with force=true', function () {
    actingAs($this->user);
    attachCreatorWithOrcid($this->resource, '0000-0002-1825-0097');

    // Mock called twice: first without force (warning), then with force (no
    // revalidation is performed because force bypasses the warning path, but
    // preflight still re-runs the API call – the fake just keeps returning
    // the transient error).
    $this->mock(OrcidService::class, function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->twice()
            ->andReturn([
                'valid' => false,
                'exists' => null,
                'message' => 'Timeout',
                'errorType' => 'timeout',
            ]);
    });

    // 1st call – without force → 409.
    $warning = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);
    $warning->assertStatus(409);
    $warning->assertJson(['error' => 'orcid_validation_warning']);
    expect($warning->json('warnings'))->toHaveCount(1);

    // 2nd call – with force=true → proceeds to DataCite and succeeds.
    $ok = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
        'force' => true,
    ]);
    $ok->assertStatus(200);
    $ok->assertJson(['success' => true]);
    expect($this->resource->fresh()->doi)->not->toBeNull();
});

test('registerDoi stamps orcid_verified_at and proceeds when preflight confirms the ORCID', function () {
    actingAs($this->user);
    $person = attachCreatorWithOrcid($this->resource, '0000-0002-1825-0097');

    $this->mock(OrcidService::class, function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->once()
            ->andReturn([
                'valid' => true,
                'exists' => true,
                'message' => 'Valid',
                'errorType' => null,
            ]);
    });

    $response = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
    expect($person->fresh()->orcid_verified_at)->not->toBeNull();
    expect($this->resource->fresh()->doi)->not->toBeNull();
});

test('registerDoi does not call ORCID API when resource has no ORCIDs', function () {
    actingAs($this->user);

    $this->mock(OrcidService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('validateOrcid');
    });

    $response = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertStatus(200);
});

test('registerDoi accepts the `force` boolean in the validated request', function () {
    actingAs($this->user);

    $this->mock(OrcidService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('validateOrcid');
    });

    $response = $this->postJson(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
        'force' => 'not-a-boolean',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['force']);
});
