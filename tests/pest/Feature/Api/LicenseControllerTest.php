<?php

declare(strict_types=1);

use App\Http\Controllers\LicenseController;
use App\Models\ResourceType;
use App\Models\Right;

covers(LicenseController::class);

describe('index', function () {
    it('returns all licenses as JSON', function () {
        Right::factory()->create(['identifier' => 'CC-BY-4.0', 'name' => 'Creative Commons Attribution 4.0']);
        Right::factory()->create(['identifier' => 'CC0-1.0', 'name' => 'CC0 1.0 Universal']);

        $response = $this->getJson('/api/v1/licenses');

        $response->assertOk()
            ->assertJsonFragment(['identifier' => 'CC-BY-4.0'])
            ->assertJsonFragment(['identifier' => 'CC0-1.0']);
    });
});

describe('elmo', function () {
    it('returns only active and elmo-active licenses', function () {
        Right::factory()->create([
            'identifier' => 'active-elmo',
            'name' => 'Active ELMO',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
        Right::factory()->create([
            'identifier' => 'inactive-elmo',
            'name' => 'Inactive ELMO',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/licenses/elmo', ['X-API-Key' => 'test-api-key']);

        $response->assertOk()
            ->assertJsonFragment(['identifier' => 'active-elmo'])
            ->assertJsonMissing(['identifier' => 'inactive-elmo']);
    });
});

describe('ernie', function () {
    it('returns active licenses ordered by usage count', function () {
        Right::factory()->create([
            'identifier' => 'low-usage',
            'name' => 'Low Usage',
            'is_active' => true,
            'usage_count' => 1,
        ]);
        Right::factory()->create([
            'identifier' => 'high-usage',
            'name' => 'High Usage',
            'is_active' => true,
            'usage_count' => 100,
        ]);

        $response = $this->getJson('/api/v1/licenses/ernie');

        $response->assertOk();
        $data = $response->json();
        expect($data[0]['identifier'])->toBe('high-usage');
    });
});

describe('elmoForResourceType', function () {
    it('returns 404 for unknown resource type', function () {
        $response = $this->getJson('/api/v1/licenses/elmo/NonExistentType', ['X-API-Key' => 'test-api-key']);

        $response->assertNotFound();
    });

    it('returns licenses available for specific resource type', function () {
        ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'is_active' => true]
        );

        Right::factory()->create([
            'identifier' => 'available',
            'name' => 'Available License',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $response = $this->getJson('/api/v1/licenses/elmo/Dataset', ['X-API-Key' => 'test-api-key']);

        $response->assertOk()
            ->assertJsonFragment(['identifier' => 'available']);
    });
});
