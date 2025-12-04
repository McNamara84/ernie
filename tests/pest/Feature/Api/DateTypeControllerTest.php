<?php

use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => null]);

    DateType::create(['name' => 'Accepted', 'slug' => 'accepted', 'description' => 'The date that the publisher accepted the resource.', 'active' => true, 'elmo_active' => true]);
    DateType::create(['name' => 'Available', 'slug' => 'available', 'description' => 'The date the resource is made publicly available.', 'active' => true, 'elmo_active' => false]);
    DateType::create(['name' => 'Collected', 'slug' => 'collected', 'description' => 'The date the resource content was collected.', 'active' => false, 'elmo_active' => true]);
    DateType::create(['name' => 'Other', 'slug' => 'other', 'description' => 'Other date type.', 'active' => false, 'elmo_active' => false]);
});

test('returns all date types ordered by name', function () {
    $response = $this->getJson('/api/v1/date-types')->assertOk();
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

test('returns only active and elmo-active date types', function () {
    $response = $this->getJson('/api/v1/date-types/elmo')->assertOk();
    expect($response->json())->toHaveCount(1);
    expect(array_column($response->json(), 'name'))->toBe([
        'Accepted',
    ]);
});

test('date type response includes slug and description', function () {
    $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();
    $firstType = $response->json()[0];

    expect($firstType)->toHaveKeys(['id', 'name', 'slug', 'description']);
    expect($firstType['slug'])->toBe('accepted');
    expect($firstType['description'])->toContain('publisher accepted');
});
