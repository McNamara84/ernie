<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\Right;

beforeEach(function () {
    Right::create([
        'identifier' => 'cc-by-4',
        'name' => 'CC BY 4.0',
        'is_active' => true,
        'is_elmo_active' => true,
        'usage_count' => 10,
    ]);
    Right::create([
        'identifier' => 'cc0',
        'name' => 'CC0 1.0',
        'is_active' => true,
        'is_elmo_active' => false,
        'usage_count' => 5,
    ]);
    Right::create([
        'identifier' => 'old-license',
        'name' => 'Old License',
        'is_active' => false,
        'is_elmo_active' => false,
        'usage_count' => 0,
    ]);
});

describe('index', function () {
    test('returns all licenses', function () {
        $response = $this->getJson('/api/v1/licenses');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    test('returns id, identifier, and name fields', function () {
        $response = $this->getJson('/api/v1/licenses');

        $first = $response->json()[0];
        expect($first)->toHaveKeys(['id', 'identifier', 'name']);
    });
});

describe('ernie', function () {
    test('returns active licenses ordered by usage count', function () {
        $response = $this->getJson('/api/v1/licenses/ernie');

        $response->assertOk()
            ->assertJsonCount(2);

        $identifiers = collect($response->json())->pluck('identifier')->toArray();
        expect($identifiers[0])->toBe('cc-by-4')
            ->and($identifiers)->not->toContain('old-license');
    });
});

describe('elmo', function () {
    test('returns active and elmo-active licenses', function () {
        $response = $this->getJson('/api/v1/licenses/elmo');

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['identifier'])->toBe('cc-by-4');
    });
});

describe('elmoForResourceType', function () {
    test('returns 404 for unknown resource type', function () {
        $response = $this->getJson('/api/v1/licenses/elmo/nonexistent-type');

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource type not found.']);
    });

    test('returns licenses for valid resource type', function () {
        ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

        $response = $this->getJson('/api/v1/licenses/elmo/dataset');

        $response->assertOk();
    });
});
