<?php

use App\Models\ContributorType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
});

function createContributorTypes(): ContributorType
{
    $contactPerson = ContributorType::create([
        'name' => 'Contact Person',
        'slug' => 'ContactPerson',
        'is_active' => true,
    ]);

    ContributorType::create([
        'name' => 'Data Curator',
        'slug' => 'DataCurator',
        'is_active' => true,
    ]);

    ContributorType::create([
        'name' => 'Inactive Type',
        'slug' => 'InactiveType',
        'is_active' => false,
    ]);

    return $contactPerson;
}

it('returns contributor types for persons (ERNIE)', function () {
    createContributorTypes();

    $response = getJson('/api/v1/roles/contributor-persons/ernie')
        ->assertOk();

    expect($response->json())->toBeArray()
        ->and(count($response->json()))->toBeGreaterThanOrEqual(2);
});

it('returns contributor types for persons (ELMO)', function () {
    createContributorTypes();

    $response = getJson('/api/v1/roles/contributor-persons/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk();

    expect($response->json())->toBeArray()
        ->and(count($response->json()))->toBeGreaterThanOrEqual(2);
});

it('returns contributor types for institutions (ERNIE)', function () {
    createContributorTypes();

    $response = getJson('/api/v1/roles/contributor-institutions/ernie')
        ->assertOk();

    expect($response->json())->toBeArray();
});

it('returns contributor types for institutions (ELMO)', function () {
    createContributorTypes();

    $response = getJson('/api/v1/roles/contributor-institutions/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk();

    expect($response->json())->toBeArray();
});

it('rejects requests without API key when one is configured (ELMO)', function () {
    createContributorTypes();

    getJson('/api/v1/roles/contributor-persons/elmo')
        ->assertUnauthorized();
});

it('accepts requests with correct API key (ELMO)', function () {
    createContributorTypes();

    getJson('/api/v1/roles/contributor-persons/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk();
});

it('rejects requests when no API key is configured on server (ELMO)', function () {
    createContributorTypes();

    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/roles/contributor-persons/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
