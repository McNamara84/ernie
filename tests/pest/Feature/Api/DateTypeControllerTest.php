<?php

use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);

    DateType::create(['name' => 'Accepted', 'slug' => 'accepted', 'is_active' => true]);
    DateType::create(['name' => 'Available', 'slug' => 'available', 'is_active' => true]);
    DateType::create(['name' => 'Collected', 'slug' => 'collected', 'is_active' => false]);
    DateType::create(['name' => 'Other', 'slug' => 'other', 'is_active' => false]);
});

test('returns all date types ordered by name', function () {
    $response = $this->getJson('/api/v1/date-types')->assertOk();
    // 4 from beforeEach + 1 Coverage from migration = 5 total
    expect($response->json())->toHaveCount(5);
    expect(array_column($response->json(), 'name'))->toBe([
        'Accepted',
        'Available',
        'Collected',
        'Coverage',
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
    $response = $this->getJson('/api/v1/date-types/elmo')->assertOk();
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
