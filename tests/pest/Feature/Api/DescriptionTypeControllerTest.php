<?php

use App\Models\DescriptionType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);

    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true, 'is_elmo_active' => true]);
    DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true, 'is_elmo_active' => false]);
    DescriptionType::create(['name' => 'Other', 'slug' => 'Other', 'is_active' => false, 'is_elmo_active' => false]);
    DescriptionType::create(['name' => 'TechnicalInfo', 'slug' => 'TechnicalInfo', 'is_active' => true, 'is_elmo_active' => true]);
});

test('returns all description types ordered by name', function () {
    $response = $this->getJson('/api/v1/description-types')->assertOk();

    expect($response->json())->toHaveCount(4);
    expect(array_column($response->json(), 'name'))->toBe([
        'Abstract',
        'Methods',
        'Other',
        'TechnicalInfo',
    ]);
});

test('returns only active description types for Ernie', function () {
    $response = $this->getJson('/api/v1/description-types/ernie')->assertOk();

    expect($response->json())->toHaveCount(3);
    expect(array_column($response->json(), 'name'))->toBe([
        'Abstract',
        'Methods',
        'TechnicalInfo',
    ]);
});

test('returns only active and elmo-active description types for Elmo', function () {
    $response = $this->getJson('/api/v1/description-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk();

    // Only Abstract and TechnicalInfo are both is_active=true AND is_elmo_active=true
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Abstract',
        'TechnicalInfo',
    ]);
});

test('description type response includes slug', function () {
    $response = $this->getJson('/api/v1/description-types/ernie')->assertOk();
    $firstType = $response->json()[0];

    expect($firstType)->toHaveKeys(['id', 'name', 'slug']);
    expect($firstType['slug'])->toBe('Abstract');
});
