<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);

    ResourceType::create(['name' => 'Alpha', 'slug' => 'alpha', 'description' => 'Alpha description', 'is_active' => true, 'is_elmo_active' => true]);
    ResourceType::create(['name' => 'Bravo', 'slug' => 'bravo', 'description' => 'Bravo description', 'is_active' => true, 'is_elmo_active' => false]);
    ResourceType::create(['name' => 'Charlie', 'slug' => 'charlie', 'description' => 'Charlie description', 'is_active' => false, 'is_elmo_active' => true]);
    ResourceType::create(['name' => 'Delta', 'slug' => 'delta', 'description' => null, 'is_active' => false, 'is_elmo_active' => false]);
});

test('returns all resource types ordered by name with description', function () {
    $response = $this->getJson('/api/v1/resource-types')->assertOk();
    expect($response->json())->toHaveCount(4);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
        'Charlie',
        'Delta',
    ]);
    expect($response->json('0.description'))->toBe('Alpha description');
    expect($response->json('3.description'))->toBeNull();
});

test('returns only active resource types for Ernie with description', function () {
    $response = $this->getJson('/api/v1/resource-types/ernie')->assertOk();
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
    ]);
    expect($response->json('0.description'))->toBe('Alpha description');
});

test('returns only active and elmo-active resource types with description', function () {
    $response = $this->getJson('/api/v1/resource-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk();
    expect($response->json())->toHaveCount(1);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
    ]);
    expect($response->json('0.description'))->toBe('Alpha description');
});
