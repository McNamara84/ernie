<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Orcid\OrcidPreflightValidator;
use App\Services\OrcidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * A valid ORCID iD with a correct checksum (issue #610).
 *
 * Using a well-known example from Chen et al. that passes ISO 7064
 * mod 11-2 so format/checksum offline gates always succeed.
 */
const VALID_ORCID = '0000-0002-1825-0097';

/**
 * Well-formed but checksum-invalid ORCID.
 */
const INVALID_CHECKSUM_ORCID = '0000-0002-1825-0099';

function makeResourceWithCreatorOrcid(string $orcid, string $scheme = 'ORCID'): array
{
    $resource = Resource::factory()->create();
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

    return [$resource->fresh(), $person];
}

function mockOrcidService(callable $setup): OrcidService
{
    return tap(Mockery::mock(OrcidService::class), $setup);
}

it('returns a clean result when no creators carry an ORCID', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    ResourceCreator::factory()
        ->forPerson($person)
        ->position(0)
        ->create(['resource_id' => $resource->id]);

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource->fresh());

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->needsConfirmation)->toBeFalse()
        ->and($result->invalid)->toBe([])
        ->and($result->warnings)->toBe([]);
});

it('skips identifiers that are explicitly tagged with a non-ORCID scheme', function () {
    [$resource, $person] = makeResourceWithCreatorOrcid('0000-0001-5109-3700', 'ISNI');

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeFalse()
        ->and($person->fresh()->orcid_verified_at)->toBeNull();
});

it('hard-blocks on malformed ORCID format without hitting the network', function () {
    [$resource] = makeResourceWithCreatorOrcid('not-an-orcid');

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeTrue()
        ->and($result->invalid)->toHaveCount(1)
        ->and($result->invalid[0]->reason)->toBe('format')
        ->and($result->invalid[0]->severity)->toBe('blocking')
        ->and($result->invalid[0]->role)->toBe('creator');
});

it('hard-blocks on checksum-invalid ORCID without hitting the network', function () {
    [$resource] = makeResourceWithCreatorOrcid(INVALID_CHECKSUM_ORCID);

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeTrue()
        ->and($result->invalid[0]->reason)->toBe('checksum');
});

it('stamps orcid_verified_at and returns clean result when the API confirms the ORCID', function () {
    [$resource, $person] = makeResourceWithCreatorOrcid(VALID_ORCID);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->once()
            ->with(VALID_ORCID)
            ->andReturn([
                'valid' => true,
                'exists' => true,
                'message' => 'Valid',
                'errorType' => null,
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->needsConfirmation)->toBeFalse()
        ->and($result->invalid)->toBe([])
        ->and($result->warnings)->toBe([])
        ->and($person->fresh()->orcid_verified_at)->not->toBeNull();
});

it('hard-blocks on not_found responses', function () {
    [$resource, $person] = makeResourceWithCreatorOrcid(VALID_ORCID);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->andReturn([
                'valid' => false,
                'exists' => false,
                'message' => 'Not found',
                'errorType' => 'not_found',
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeTrue()
        ->and($result->invalid)->toHaveCount(1)
        ->and($result->invalid[0]->reason)->toBe('not_found')
        ->and($person->fresh()->orcid_verified_at)->toBeNull();
});

it('emits a warning (not a block) on timeout and requires confirmation', function () {
    [$resource] = makeResourceWithCreatorOrcid(VALID_ORCID);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->andReturn([
                'valid' => false,
                'exists' => null,
                'message' => 'Timeout',
                'errorType' => 'timeout',
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->needsConfirmation)->toBeTrue()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]->severity)->toBe('warning')
        ->and($result->warnings[0]->reason)->toBe('timeout');
});

it('suppresses needsConfirmation when force=true even with warnings', function () {
    [$resource] = makeResourceWithCreatorOrcid(VALID_ORCID);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->andReturn([
                'valid' => false,
                'exists' => null,
                'message' => 'API error',
                'errorType' => 'api_error',
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource, force: true);

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->needsConfirmation)->toBeFalse()
        ->and($result->warnings)->toHaveCount(1);
});

it('validates contributors in addition to creators', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'given_name' => 'Chris',
        'family_name' => 'Contributor',
        'name_identifier' => VALID_ORCID,
        'name_identifier_scheme' => 'ORCID',
    ]);
    ResourceContributor::factory()
        ->forPerson($person)
        ->atPosition(0)
        ->create(['resource_id' => $resource->id]);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->once()
            ->andReturn([
                'valid' => false,
                'exists' => false,
                'message' => 'Not found',
                'errorType' => 'not_found',
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource->fresh());

    expect($result->shouldBlock)->toBeTrue()
        ->and($result->invalid[0]->role)->toBe('contributor');
});

it('produces a serializable payload shape', function () {
    [$resource] = makeResourceWithCreatorOrcid('not-an-orcid');

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);

    $payload = (new OrcidPreflightValidator($orcid))->validate($resource)->toPayload();

    expect($payload)->toHaveKeys(['invalid', 'warnings'])
        ->and($payload['invalid'])->toBeArray()
        ->and($payload['invalid'][0])->toHaveKeys([
            'severity', 'reason', 'role', 'position', 'orcid', 'displayName',
        ]);
});

it('skips creators with null name_identifier', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);
    ResourceCreator::factory()
        ->forPerson($person)
        ->position(0)
        ->create(['resource_id' => $resource->id]);

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource->fresh());

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->warnings)->toBe([]);
});

it('skips institution creators (non-Person morph target)', function () {
    $resource = Resource::factory()->create();
    \App\Models\ResourceCreator::factory()
        ->forInstitution()
        ->position(0)
        ->create(['resource_id' => $resource->id]);

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);
    $orcid->shouldNotReceive('validateOrcid');

    $result = (new OrcidPreflightValidator($orcid))->validate($resource->fresh());

    expect($result->shouldBlock)->toBeFalse();
});

it('falls back to "Unnamed person" when display name cannot be built', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'given_name' => '',
        'family_name' => '',
        'name_identifier' => 'not-an-orcid',
        'name_identifier_scheme' => 'ORCID',
    ]);
    ResourceCreator::factory()
        ->forPerson($person)
        ->position(0)
        ->create(['resource_id' => $resource->id]);

    /** @var OrcidService&MockInterface $orcid */
    $orcid = Mockery::mock(OrcidService::class);

    $result = (new OrcidPreflightValidator($orcid))->validate($resource->fresh());

    expect($result->invalid[0]->displayName)->toBe('Unnamed person');
});

it('normalizes unexpected API error types to "unknown" warning', function () {
    [$resource] = makeResourceWithCreatorOrcid(VALID_ORCID);

    $orcid = mockOrcidService(function (MockInterface $mock) {
        $mock->shouldReceive('validateOrcid')
            ->andReturn([
                'valid' => false,
                'exists' => false,
                'message' => 'Weird',
                'errorType' => 'something_we_never_saw_before',
            ]);
    });

    $result = (new OrcidPreflightValidator($orcid))->validate($resource);

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]->reason)->toBe('unknown');
});

it('OrcidPreflightResult::clean() produces a result with no issues', function () {
    $result = \App\Services\Orcid\OrcidPreflightResult::clean();

    expect($result->shouldBlock)->toBeFalse()
        ->and($result->needsConfirmation)->toBeFalse()
        ->and($result->invalid)->toBe([])
        ->and($result->warnings)->toBe([])
        ->and($result->toPayload())->toBe(['invalid' => [], 'warnings' => []]);
});
