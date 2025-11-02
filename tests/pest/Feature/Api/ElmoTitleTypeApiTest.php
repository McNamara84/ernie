<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
});

function createElmoTitleTypes(): TitleType
{
    $enabled = TitleType::create([
        'name' => 'Main',
        'slug' => 'main',
        'active' => true,
        'elmo_active' => true,
    ]);

    TitleType::create([
        'name' => 'Alt',
        'slug' => 'alt',
        'active' => true,
        'elmo_active' => false,
    ]);

    return $enabled;
}

it('returns only title types enabled for ELMO', function () {
    $enabled = createElmoTitleTypes();

    $response = getJson('/api/v1/title-types/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Main', 'slug' => 'main']);
});

it('rejects title type requests without an API key when one is configured', function () {
    createElmoTitleTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/title-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects title type requests with an invalid API key', function () {
    createElmoTitleTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows title type requests with a valid API key header', function () {
    $enabled = createElmoTitleTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Main', 'slug' => 'main']);
});

it('allows title type requests with a valid API key query parameter', function () {
    $enabled = createElmoTitleTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/title-types/elmo?api_key=secret-key')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Main', 'slug' => 'main']);
});
