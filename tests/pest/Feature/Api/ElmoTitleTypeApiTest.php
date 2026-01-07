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
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    TitleType::create([
        'name' => 'Alt',
        'slug' => 'alt',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    return $enabled;
}

it('returns only title types enabled for ELMO', function () {
    $enabled = createElmoTitleTypes();

    $response = getJson('/api/v1/title-types/elmo')
        ->assertOk()
        // 2 types: 'Main' created here + 'Main Title' from migration, ordered alphabetically
        ->assertJsonCount(2);

    // Verify exact ordering by name
    expect(array_column($response->json(), 'name'))->toBe([
        'Main',
        'Main Title',
    ]);
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
        // 2 types: 'Main' created here + 'Main Title' from migration, ordered alphabetically
        ->assertJsonCount(2);

    // Verify exact ordering by name
    expect(array_column($response->json(), 'name'))->toBe([
        'Main',
        'Main Title',
    ]);
});

it('rejects API keys in query parameters for security', function () {
    createElmoTitleTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/title-types/elmo?api_key=secret-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});
