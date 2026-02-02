<?php

use App\Models\ResourceType;
use Database\Seeders\ResourceTypeDescriptionSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ResourceTypeDescriptionSeeder', function () {
    it('has description keys matching ResourceTypeSeeder names', function () {
        // Get the names from ResourceTypeSeeder by running it
        $this->seed(ResourceTypeSeeder::class);
        $seededNames = ResourceType::pluck('name')->toArray();

        // Get the keys from ResourceTypeDescriptionSeeder
        $descriptionKeys = ResourceTypeDescriptionSeeder::getDescriptionKeys();

        // Every description key must exist in the seeded resource types
        $missingInSeeder = array_diff($descriptionKeys, $seededNames);

        expect($missingInSeeder)->toBeEmpty(
            "ResourceTypeDescriptionSeeder has descriptions for types not in ResourceTypeSeeder: '".
            implode("', '", $missingInSeeder).
            "'. Check for naming mismatches (e.g., 'BookChapter' vs 'Book Chapter')."
        );
    });

    it('provides descriptions for all resource types', function () {
        $this->seed(ResourceTypeSeeder::class);
        $seededNames = ResourceType::pluck('name')->toArray();

        $descriptionKeys = ResourceTypeDescriptionSeeder::getDescriptionKeys();

        // Every seeded resource type should have a description
        $missingDescriptions = array_diff($seededNames, $descriptionKeys);

        expect($missingDescriptions)->toBeEmpty(
            "ResourceTypeSeeder has types without descriptions: '".
            implode("', '", $missingDescriptions)."'."
        );
    });

    it('updates all resource types with descriptions after seeding', function () {
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ResourceTypeDescriptionSeeder::class);

        $resourceTypesWithoutDescription = ResourceType::whereNull('description')
            ->orWhere('description', '')
            ->pluck('name')
            ->toArray();

        expect($resourceTypesWithoutDescription)->toBeEmpty(
            'The following resource types have no description: '.implode(', ', $resourceTypesWithoutDescription)
        );
    });
});

describe('ResourceType API with descriptions', function () {
    it('returns descriptions in API response', function () {
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ResourceTypeDescriptionSeeder::class);

        $response = $this->getJson('/api/v1/resource-types/ernie');

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => ['id', 'name', 'description'],
        ]);

        // Verify at least some descriptions are present
        $data = $response->json();
        $hasDescriptions = collect($data)->filter(fn ($item) => ! empty($item['description']))->count();

        expect($hasDescriptions)->toBeGreaterThan(0, 'API should return resource types with descriptions');
    });
});
