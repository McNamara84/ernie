<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    
    TitleType::create(['name' => 'Alpha', 'slug' => 'alpha', 'active' => true, 'elmo_active' => true]);
    TitleType::create(['name' => 'Bravo', 'slug' => 'bravo', 'active' => true, 'elmo_active' => false]);
    TitleType::create(['name' => 'Charlie', 'slug' => 'charlie', 'active' => false, 'elmo_active' => true]);
    TitleType::create(['name' => 'Delta', 'slug' => 'delta', 'active' => false, 'elmo_active' => false]);
});

test('returns all title types ordered by name', function () {
    $response = $this->getJson('/api/v1/title-types')->assertOk();
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
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
    ]);
});

test('returns only active and elmo-active title types', function () {
    $response = $this->getJson('/api/v1/title-types/elmo')->assertOk();
    expect($response->json())->toHaveCount(1);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
    ]);
});
