<?php

use App\Models\Right;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
});

function createElmoRights(): Right
{
    $enabled = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License 2.0',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    return $enabled;
}

it('lists all licenses', function () {
    Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/licenses')
        ->assertOk()
        ->assertJsonCount(1);
});

it('lists ELMO-active licenses', function () {
    $enabled = createElmoRights();

    $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', $enabled->identifier);
});

it('lists ERNIE-active licenses', function () {
    Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'is_active' => true,
    ]);
    Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License 2.0',
        'is_active' => false,
    ]);

    $this->getJson('/api/v1/licenses/ernie')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', 'MIT');
});

it('rejects license requests without an API key when one is configured', function () {
    createElmoRights();

    $this->getJson('/api/v1/licenses/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects license requests with an invalid API key', function () {
    createElmoRights();

    $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows license requests with a valid API key header', function () {
    $enabled = createElmoRights();

    $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', $enabled->identifier);
});

it('rejects API keys in query parameters for security', function () {
    createElmoRights();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    $this->getJson('/api/v1/licenses/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects license requests when no API key is configured on server', function () {
    createElmoRights();

    config(['services.ernie.api_key' => null]);

    $this->getJson('/api/v1/licenses/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});

it('sorts ERNIE licenses by usage count descending with alphabetical fallback', function () {
    // Create rights with different usage counts
    Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'is_active' => true,
        'usage_count' => 10,
    ]);
    Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License 2.0',
        'is_active' => true,
        'usage_count' => 5,
    ]);
    Right::create([
        'identifier' => 'GPL-3.0',
        'name' => 'GNU General Public License v3.0',
        'is_active' => true,
        'usage_count' => 5,
    ]);
    Right::create([
        'identifier' => 'BSD-3',
        'name' => 'BSD 3-Clause',
        'is_active' => true,
        'usage_count' => 0,
    ]);

    $response = $this->getJson('/api/v1/licenses/ernie')
        ->assertOk()
        ->assertJsonCount(4);

    // Verify order: MIT (10), Apache (5), GPL (5 alphabetical), BSD (0)
    expect($response->json('0.identifier'))->toBe('MIT')
        ->and($response->json('1.identifier'))->toBe('Apache-2.0')
        ->and($response->json('2.identifier'))->toBe('GPL-3.0')
        ->and($response->json('3.identifier'))->toBe('BSD-3');
});
