<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
});

function createElmoRoles(): Role
{
    $enabled = Role::create([
        'name' => 'Researcher',
        'slug' => 'researcher',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => true,
    ]);

    Role::create([
        'name' => 'Inactive for Elmo',
        'slug' => 'inactive-for-elmo',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        'is_active_in_ernie' => true,
        'is_active_in_elmo' => false,
    ]);

    return $enabled;
}

it('returns only contributor person roles enabled for ELMO', function () {
    $enabled = createElmoRoles();

    $response = getJson('/api/v1/roles/contributor-persons/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))->toBe([
        'id' => $enabled->id,
        'name' => 'Researcher',
        'slug' => 'researcher',
    ]);
});

it('rejects requests without an API key when one is configured', function () {
    createElmoRoles();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/roles/contributor-persons/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoRoles();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/roles/contributor-persons/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    $enabled = createElmoRoles();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/roles/contributor-persons/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))->toBe([
        'id' => $enabled->id,
        'name' => 'Researcher',
        'slug' => 'researcher',
    ]);
});

it('allows requests with a valid API key query parameter', function () {
    $enabled = createElmoRoles();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/roles/contributor-persons/elmo?api_key=secret-key')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))->toBe([
        'id' => $enabled->id,
        'name' => 'Researcher',
        'slug' => 'researcher',
    ]);
});
