<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceTypeController;
use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(ResourceTypeController::class);

beforeEach(function () {
    ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset', 'is_active' => true, 'is_elmo_active' => true]);
    ResourceType::factory()->create(['name' => 'Software', 'slug' => 'software', 'is_active' => true, 'is_elmo_active' => false]);
    ResourceType::factory()->create(['name' => 'Sound', 'slug' => 'sound', 'is_active' => false, 'is_elmo_active' => false]);
});

describe('index', function () {
    it('returns all resource types', function () {
        $response = $this->getJson('/api/v1/resource-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns resource types ordered by name', function () {
        $response = $this->getJson('/api/v1/resource-types');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['Dataset', 'Software', 'Sound']);
    });

    it('returns id, name and description fields', function () {
        $response = $this->getJson('/api/v1/resource-types');

        $response->assertJsonStructure([
            ['id', 'name', 'description'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active resource types with valid API key', function () {
        $response = $this->getJson('/api/v1/resource-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['name'])->toBe('Dataset');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/resource-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active resource types', function () {
        $response = $this->getJson('/api/v1/resource-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive resource types', function () {
        $response = $this->getJson('/api/v1/resource-types/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Sound');
    });
});
