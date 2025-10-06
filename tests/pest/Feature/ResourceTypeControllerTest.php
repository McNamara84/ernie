<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    
    ResourceType::create(['name' => 'Alpha', 'slug' => 'alpha', 'active' => true, 'elmo_active' => true]);
    ResourceType::create(['name' => 'Bravo', 'slug' => 'bravo', 'active' => true, 'elmo_active' => false]);
    ResourceType::create(['name' => 'Charlie', 'slug' => 'charlie', 'active' => false, 'elmo_active' => true]);
    ResourceType::create(['name' => 'Delta', 'slug' => 'delta', 'active' => false, 'elmo_active' => false]);
});

test('returns all resource types ordered by name', function () {
    $response = $this->getJson('/api/v1/resource-types')->assertOk();
    expect($response->json())->toHaveCount(4);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
        'Charlie',
        'Delta',
    ]);
});

test('returns only active resource types for Ernie', function () {
    $response = $this->getJson('/api/v1/resource-types/ernie')->assertOk();
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
    ]);
});

test('returns only active and elmo-active resource types', function () {
    $response = $this->getJson('/api/v1/resource-types/elmo')->assertOk();
    expect($response->json())->toHaveCount(1);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
    ]);
});
