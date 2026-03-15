<?php

use App\Models\DescriptionType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
});

function createElmoDescriptionTypes(): void
{
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true, 'is_elmo_active' => true]);
    DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true, 'is_elmo_active' => true]);
    DescriptionType::create(['name' => 'Other', 'slug' => 'Other', 'is_active' => true, 'is_elmo_active' => false]);
    DescriptionType::create(['name' => 'TechnicalInfo', 'slug' => 'TechnicalInfo', 'is_active' => false, 'is_elmo_active' => true]);
}

it('returns only active and elmo-active description types for ELMO', function () {
    createElmoDescriptionTypes();

    $response = getJson('/api/v1/description-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(2);

    // Only Abstract and Methods are both is_active=true AND is_elmo_active=true
    expect($response->json('0.name'))->toBe('Abstract');
    expect($response->json('0.slug'))->toBe('Abstract');
    expect($response->json('1.name'))->toBe('Methods');
});

it('rejects requests without an API key when one is configured', function () {
    createElmoDescriptionTypes();

    getJson('/api/v1/description-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoDescriptionTypes();

    getJson('/api/v1/description-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    createElmoDescriptionTypes();

    $response = getJson('/api/v1/description-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Abstract');
    expect($response->json('1.name'))->toBe('Methods');
});

it('rejects API keys in query parameters for security', function () {
    createElmoDescriptionTypes();

    getJson('/api/v1/description-types/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests when no API key is configured on server', function () {
    createElmoDescriptionTypes();

    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/description-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
