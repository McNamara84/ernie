<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => 'test-api-key']);

    // Create all test data - tests should not depend on seeded data
    TitleType::create(['name' => 'Alpha', 'slug' => 'alpha', 'is_active' => true, 'is_elmo_active' => true]);
    TitleType::create(['name' => 'Bravo', 'slug' => 'bravo', 'is_active' => true, 'is_elmo_active' => false]);
    TitleType::create(['name' => 'Charlie', 'slug' => 'charlie', 'is_active' => false, 'is_elmo_active' => true]);
    TitleType::create(['name' => 'Delta', 'slug' => 'delta', 'is_active' => false, 'is_elmo_active' => false]);
});

test('returns all title types ordered by name', function () {
    $response = $this->getJson('/api/v1/title-types')->assertOk();
    // 4 types created in beforeEach, ordered alphabetically
    expect($response->json())->toHaveCount(4);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
        'Charlie',
        'Delta',
    ]);
});

test('returns only active title types for Ernie', function () {
    $response = $this->getJson('/api/v1/title-types/ernie')->assertOk();
    // 2 active types: Alpha, Bravo (is_active=true)
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
    ]);
});

test('returns only active and elmo-active title types', function () {
    $response = $this->getJson('/api/v1/title-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk();
    // 1 type: Alpha (is_active=true AND is_elmo_active=true)
    expect($response->json())->toHaveCount(1);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
    ]);
});
