<?php

use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => 'test-api-key']);

    // Create all test data - tests should not depend on seeded data
    DateType::create(['name' => 'Accepted', 'slug' => 'accepted', 'is_active' => true]);
    DateType::create(['name' => 'Available', 'slug' => 'available', 'is_active' => true]);
    DateType::create(['name' => 'Collected', 'slug' => 'collected', 'is_active' => false]);
    DateType::create(['name' => 'Other', 'slug' => 'other', 'is_active' => false]);
});

test('returns all date types ordered by name', function () {
    $response = $this->getJson('/api/v1/date-types')->assertOk();
    // 4 types created in beforeEach, ordered alphabetically
    expect($response->json())->toHaveCount(4);
    expect(array_column($response->json(), 'name'))->toBe([
        'Accepted',
        'Available',
        'Collected',
        'Other',
    ]);
});

test('returns only active date types for Ernie', function () {
    $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Accepted',
        'Available',
    ]);
});

test('returns only active date types for Elmo (same as Ernie)', function () {
    $response = $this->getJson('/api/v1/date-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk();
    expect($response->json())->toHaveCount(2);
    expect(array_column($response->json(), 'name'))->toBe([
        'Accepted',
        'Available',
    ]);
});

test('date type response includes slug', function () {
    $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();
    $firstType = $response->json()[0];

    expect($firstType)->toHaveKeys(['id', 'name', 'slug']);
    expect($firstType['slug'])->toBe('accepted');
});
