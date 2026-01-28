<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => 'test-api-key']);
});

function createElmoResourceTypes(): ResourceType
{
    $enabled = ResourceType::create([
        'name' => 'Type A',
        'slug' => 'type-a',
        'description' => 'Description for Type A',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    ResourceType::create([
        'name' => 'Type B',
        'slug' => 'type-b',
        'description' => 'Description for Type B',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    return $enabled;
}

it('returns only resource types enabled for ELMO with description', function () {
    $enabled = createElmoResourceTypes();

    $response = getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A', 'description' => 'Description for Type A']);
});

it('rejects requests without an API key when one is configured', function () {
    createElmoResourceTypes();

    getJson('/api/v1/resource-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoResourceTypes();

    getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    $enabled = createElmoResourceTypes();

    $response = getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A', 'description' => 'Description for Type A']);
});

it('rejects API keys in query parameters for security', function () {
    createElmoResourceTypes();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/resource-types/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests when no API key is configured on server', function () {
    createElmoResourceTypes();

    config(['services.elmo.api_key' => null]);

    getJson('/api/v1/resource-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});

it('rejects requests when API key is configured as empty string', function () {
    createElmoResourceTypes();

    config(['services.elmo.api_key' => '']);

    getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'any-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
