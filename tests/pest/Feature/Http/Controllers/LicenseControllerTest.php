<?php

declare(strict_types=1);

use App\Http\Controllers\LicenseController;
use App\Models\ResourceType;
use App\Models\Right;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(LicenseController::class);

beforeEach(function () {
    Right::factory()->create(['identifier' => 'CC-BY-4.0', 'name' => 'CC BY 4.0', 'is_active' => true, 'is_elmo_active' => true, 'usage_count' => 10]);
    Right::factory()->create(['identifier' => 'CC0-1.0', 'name' => 'CC0 1.0', 'is_active' => true, 'is_elmo_active' => false, 'usage_count' => 5]);
    Right::factory()->create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => false, 'is_elmo_active' => false, 'usage_count' => 1]);
});

describe('index', function () {
    it('returns all licenses', function () {
        $response = $this->getJson('/api/v1/licenses');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns licenses ordered by name', function () {
        $response = $this->getJson('/api/v1/licenses');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['CC BY 4.0', 'CC0 1.0', 'MIT License']);
    });
});

describe('elmo', function () {
    it('returns only elmo-active licenses with valid API key', function () {
        $response = $this->getJson('/api/v1/licenses/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['identifier'])->toBe('CC-BY-4.0');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/licenses/elmo');

        $response->assertUnauthorized();
    });
});

describe('elmoForResourceType', function () {
    it('returns licenses available for a specific resource type', function () {
        $resourceType = ResourceType::factory()->create(['slug' => 'dataset']);

        // No exclusions — both elmo-active licenses should be available
        $response = $this->getJson('/api/v1/licenses/elmo/dataset', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);
    });

    it('excludes licenses that are excluded for the resource type', function () {
        $resourceType = ResourceType::factory()->create(['slug' => 'software']);

        // Exclude the CC-BY-4.0 license for software
        $license = Right::where('identifier', 'CC-BY-4.0')->first();
        $license->excludedResourceTypes()->attach($resourceType->id);

        $response = $this->getJson('/api/v1/licenses/elmo/software', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(0);
    });

    it('returns 404 for unknown resource type slug', function () {
        $response = $this->getJson('/api/v1/licenses/elmo/nonexistent', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });
});

describe('ernie', function () {
    it('returns only active licenses', function () {
        $response = $this->getJson('/api/v1/licenses/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('returns licenses ordered by usage count', function () {
        $response = $this->getJson('/api/v1/licenses/ernie');

        $identifiers = collect($response->json())->pluck('identifier')->all();
        expect($identifiers[0])->toBe('CC-BY-4.0');
    });
});
