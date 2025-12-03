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
        'description' => 'The date that the publisher accepted the resource.',
        'active' => true,
        'elmo_active' => true,
    ]);

    DateType::create([
        'name' => 'Available',
        'slug' => 'available',
        'description' => 'The date the resource is made publicly available.',
        'active' => true,
        'elmo_active' => false,
    ]);

    return $enabled;
}

it('returns only date types enabled for ELMO', function () {
    $enabled = createElmoDateTypes();

    $response = getJson('/api/v1/date-types/elmo')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0.id'))->toBe($enabled->id);
    expect($response->json('0.name'))->toBe('Accepted');
    expect($response->json('0.slug'))->toBe('accepted');
    expect($response->json('0.description'))->toContain('publisher accepted');
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
    $enabled = createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'secret-key'])
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0.id'))->toBe($enabled->id);
    expect($response->json('0.name'))->toBe('Accepted');
});

it('allows requests with a valid API key query parameter', function () {
    $enabled = createElmoDateTypes();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/date-types/elmo?api_key=secret-key')
        ->assertOk()
        ->assertJsonCount(1);

    expect($response->json('0.id'))->toBe($enabled->id);
    expect($response->json('0.name'))->toBe('Accepted');
});
