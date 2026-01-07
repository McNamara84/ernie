<?php

use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);

    // Note: Migration creates MainTitle by default, so we have 5 TitleTypes total
    TitleType::create(['name' => 'Alpha', 'slug' => 'alpha', 'is_active' => true, 'is_elmo_active' => true]);
    TitleType::create(['name' => 'Bravo', 'slug' => 'bravo', 'is_active' => true, 'is_elmo_active' => false]);
    TitleType::create(['name' => 'Charlie', 'slug' => 'charlie', 'is_active' => false, 'is_elmo_active' => true]);
    TitleType::create(['name' => 'Delta', 'slug' => 'delta', 'is_active' => false, 'is_elmo_active' => false]);
});

test('returns all title types ordered by name', function () {
    $response = $this->getJson('/api/v1/title-types')->assertOk();
    // 5 types: Alpha, Bravo, Charlie, Delta + Main Title from migration, ordered alphabetically
    expect($response->json())->toHaveCount(5);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
        'Charlie',
        'Delta',
        'Main Title',
    ]);
});

test('returns only active title types for Ernie', function () {
    $response = $this->getJson('/api/v1/title-types/ernie')->assertOk();
    // 3 active types: Alpha, Bravo + Main Title from migration, ordered alphabetically
    expect($response->json())->toHaveCount(3);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Bravo',
        'Main Title',
    ]);
});

test('returns only active and elmo-active title types', function () {
    $response = $this->getJson('/api/v1/title-types/elmo')->assertOk();
    // 2 active+elmo types: Alpha + Main Title from migration, ordered alphabetically
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Alpha',
        'Main Title',
    ]);
});
