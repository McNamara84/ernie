<?php

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
});

function createElmoLicenses(): License
{
    $enabled = License::create(['identifier' => 'MIT', 'name' => 'MIT License', 'active' => true, 'elmo_active' => true]);
    License::create(['identifier' => 'Apache', 'name' => 'Apache', 'active' => true, 'elmo_active' => false]);

    return $enabled;
}

it('lists all licenses', function () {
    License::create(['identifier' => 'MIT', 'name' => 'MIT License']);

    $this->getJson('/api/v1/licenses')
        ->assertOk()
        ->assertJsonCount(1);
});

it('lists ELMO-active licenses', function () {
    $enabled = createElmoLicenses();

    $this->getJson('/api/v1/licenses/elmo')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', $enabled->identifier);
});

it('lists ERNIE-active licenses', function () {
    License::create(['identifier' => 'MIT', 'name' => 'MIT License', 'active' => true]);
    License::create(['identifier' => 'Apache', 'name' => 'Apache', 'active' => false]);

    $this->getJson('/api/v1/licenses/ernie')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', 'MIT');
});

it('rejects license requests without an API key when one is configured', function () {
    createElmoLicenses();

    config(['services.elmo.api_key' => 'secret-key']);

    $this->getJson('/api/v1/licenses/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects license requests with an invalid API key', function () {
    createElmoLicenses();

    config(['services.elmo.api_key' => 'secret-key']);

    $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows license requests with a valid API key header', function () {
    $enabled = createElmoLicenses();

    config(['services.elmo.api_key' => 'secret-key']);

    $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', $enabled->identifier);
});

it('allows license requests with a valid API key query parameter', function () {
    $enabled = createElmoLicenses();

    config(['services.elmo.api_key' => 'secret-key']);

    $this->getJson('/api/v1/licenses/elmo?api_key=secret-key')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.identifier', $enabled->identifier);
});

it('sorts ERNIE licenses by usage count descending with alphabetical fallback', function () {
    // Create licenses with different usage counts
    $mit = License::factory()->create(['identifier' => 'MIT', 'name' => 'MIT License', 'active' => true, 'usage_count' => 10]);
    $apache = License::factory()->create(['identifier' => 'Apache-2.0', 'name' => 'Apache License 2.0', 'active' => true, 'usage_count' => 5]);
    $gpl = License::factory()->create(['identifier' => 'GPL-3.0', 'name' => 'GNU General Public License v3.0', 'active' => true, 'usage_count' => 5]);
    $bsd = License::factory()->create(['identifier' => 'BSD-3', 'name' => 'BSD 3-Clause', 'active' => true, 'usage_count' => 0]);

    $response = $this->getJson('/api/v1/licenses/ernie')
        ->assertOk()
        ->assertJsonCount(4);

    // Verify order: MIT (10), Apache (5), GPL (5 alphabetical), BSD (0)
    expect($response->json('0.identifier'))->toBe('MIT')
        ->and($response->json('1.identifier'))->toBe('Apache-2.0')
        ->and($response->json('2.identifier'))->toBe('GPL-3.0')
        ->and($response->json('3.identifier'))->toBe('BSD-3');
});
