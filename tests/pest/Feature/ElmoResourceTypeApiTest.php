<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

function createElmoResourceTypes(): ResourceType
{
    $enabled = ResourceType::create([
        'name' => 'Type A',
        'slug' => 'type-a',
        'active' => true,
        'elmo_active' => true,
    ]);

    ResourceType::create([
        'name' => 'Type B',
        'slug' => 'type-b',
        'active' => true,
        'elmo_active' => false,
    ]);

    return $enabled;
}

it('returns only resource types enabled for ELMO', function () {
    $enabled = createElmoResourceTypes();

    $response = getJson('/api/v1/resource-types/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A']);
});

it('rejects requests without an API key when one is configured', function () {
    createElmoResourceTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/resource-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoResourceTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    $enabled = createElmoResourceTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A']);
});

it('allows requests with a valid API key query parameter', function () {
    $enabled = createElmoResourceTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/resource-types/elmo?api_key=secret-key')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0'))
        ->toBe(['id' => $enabled->id, 'name' => 'Type A']);
});
