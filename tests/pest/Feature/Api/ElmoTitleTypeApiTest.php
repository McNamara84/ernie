<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
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

    $response = getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        // Only 'Main' is created with is_elmo_active=true, 'Alt' has is_elmo_active=false
        ->assertJsonCount(1);

    // Verify the response contains only the ELMO-enabled type
    expect(array_column($response->json(), 'name'))->toBe([
        'Main',
    ]);
});

it('rejects title type requests without an API key when one is configured', function () {
    createElmoTitleTypes();

    getJson('/api/v1/title-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects title type requests with an invalid API key', function () {
    createElmoTitleTypes();

    getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows title type requests with a valid API key header', function () {
    $enabled = createElmoTitleTypes();

    $response = getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        // Only 'Main' is created with is_elmo_active=true
        ->assertJsonCount(1);

    // Verify the response contains only the ELMO-enabled type
    expect(array_column($response->json(), 'name'))->toBe([
        'Main',
    ]);
});

it('rejects API keys in query parameters for security', function () {
    createElmoTitleTypes();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/title-types/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects title type requests when no API key is configured on server', function () {
    createElmoTitleTypes();

    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/title-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
