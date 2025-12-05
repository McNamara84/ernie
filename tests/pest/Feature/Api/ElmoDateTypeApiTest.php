<?php

use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
});

function createElmoDateTypes(): DateType
{
    $enabled = DateType::create([
        'name' => 'Accepted',
        'slug' => 'accepted',
        'is_active' => true,
    ]);

    DateType::create([
        'name' => 'Available',
        'slug' => 'available',
        'is_active' => true,
    ]);

    DateType::create([
        'name' => 'Inactive',
        'slug' => 'inactive',
        'is_active' => false,
    ]);

    return $enabled;
}

it('returns only active date types for ELMO (same as Ernie)', function () {
    createElmoDateTypes();

    $response = getJson('/api/v1/date-types/elmo')
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Accepted');
    expect($response->json('0.slug'))->toBe('accepted');
    expect($response->json('1.name'))->toBe('Available');
});

it('rejects requests without an API key when one is configured', function () {
    createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/date-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Accepted');
    expect($response->json('1.name'))->toBe('Available');
});

it('rejects API keys in query parameters for security', function () {
    createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/date-types/elmo?api_key=secret-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});
