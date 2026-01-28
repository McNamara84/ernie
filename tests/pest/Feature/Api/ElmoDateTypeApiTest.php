<?php

use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
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

    $response = getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Accepted');
    expect($response->json('0.slug'))->toBe('accepted');
    expect($response->json('1.name'))->toBe('Available');
});

it('rejects requests without an API key when one is configured', function () {
    createElmoDateTypes();

    getJson('/api/v1/date-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createElmoDateTypes();

    getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    createElmoDateTypes();

    $response = getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Accepted');
    expect($response->json('1.name'))->toBe('Available');
});

it('rejects API keys in query parameters for security', function () {
    createElmoDateTypes();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/date-types/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests when no API key is configured on server', function () {
    createElmoDateTypes();

    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/date-types/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
