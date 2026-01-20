<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\Right;

beforeEach(function () {
    // Configure API key for testing
    config(['services.elmo.api_key' => 'test-secret-key']);

    // Create test resource types
    $this->softwareType = ResourceType::factory()->create(['name' => 'Software', 'slug' => 'software']);
    $this->datasetType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);

    // Create licenses
    $this->mitLicense = Right::factory()->create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    $this->ccByLicense = Right::factory()->create([
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
});

describe('GET /api/v1/licenses/elmo/{resourceTypeSlug}', function () {
    it('returns all ELMO licenses for resource type without exclusions', function () {
        $this->getJson('/api/v1/licenses/elmo/software', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes licenses that have the resource type in exclusion list', function () {
        // Exclude MIT from datasets
        $this->mitLicense->excludedResourceTypes()->attach($this->datasetType->id);

        $this->getJson('/api/v1/licenses/elmo/dataset', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['identifier' => 'CC-BY-4.0'])
            ->assertJsonMissing(['identifier' => 'MIT']);
    });

    it('returns license when excluded from different resource type', function () {
        // Exclude MIT from datasets only
        $this->mitLicense->excludedResourceTypes()->attach($this->datasetType->id);

        // Should still return MIT for software
        $this->getJson('/api/v1/licenses/elmo/software', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['identifier' => 'MIT']);
    });

    it('returns 404 for unknown resource type slug', function () {
        $this->getJson('/api/v1/licenses/elmo/unknown-type', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertNotFound()
            ->assertJson(['message' => 'Resource type not found.']);
    });

    it('requires API key for elmo endpoint', function () {
        $this->getJson('/api/v1/licenses/elmo/software')
            ->assertUnauthorized();
    });

    it('respects is_elmo_active flag', function () {
        $this->ccByLicense->update(['is_elmo_active' => false]);

        $this->getJson('/api/v1/licenses/elmo/software', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['identifier' => 'MIT'])
            ->assertJsonMissing(['identifier' => 'CC-BY-4.0']);
    });

    it('respects is_active flag', function () {
        $this->mitLicense->update(['is_active' => false]);

        $this->getJson('/api/v1/licenses/elmo/software', ['X-API-Key' => config('services.elmo.api_key')])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['identifier' => 'CC-BY-4.0'])
            ->assertJsonMissing(['identifier' => 'MIT']);
    });
});

describe('Right model resource type exclusion', function () {
    it('can attach excluded resource types', function () {
        $this->mitLicense->excludedResourceTypes()->attach($this->softwareType->id);

        expect($this->mitLicense->excludedResourceTypes()->count())->toBe(1);
        expect($this->mitLicense->excludedResourceTypes()->first()->id)->toBe($this->softwareType->id);
    });

    it('can sync excluded resource types', function () {
        $this->mitLicense->excludedResourceTypes()->attach($this->softwareType->id);
        $this->mitLicense->excludedResourceTypes()->sync([$this->datasetType->id]);

        expect($this->mitLicense->excludedResourceTypes()->count())->toBe(1);
        expect($this->mitLicense->excludedResourceTypes()->first()->id)->toBe($this->datasetType->id);
    });

    it('checks availability for resource type correctly', function () {
        $this->mitLicense->excludedResourceTypes()->attach($this->softwareType->id);

        expect($this->mitLicense->isAvailableForResourceType($this->softwareType->id))->toBeFalse();
        expect($this->mitLicense->isAvailableForResourceType($this->datasetType->id))->toBeTrue();
    });

    it('scopes available for resource type correctly', function () {
        $this->mitLicense->excludedResourceTypes()->attach($this->datasetType->id);

        $availableForDataset = Right::availableForResourceType($this->datasetType->id)->get();
        $availableForSoftware = Right::availableForResourceType($this->softwareType->id)->get();

        expect($availableForDataset)->toHaveCount(1);
        expect($availableForDataset->first()->identifier)->toBe('CC-BY-4.0');
        expect($availableForSoftware)->toHaveCount(2);
    });
});

describe('ResourceType model inverse relationship', function () {
    it('can access licenses that exclude this resource type', function () {
        $this->mitLicense->excludedResourceTypes()->attach($this->softwareType->id);
        $this->ccByLicense->excludedResourceTypes()->attach($this->softwareType->id);

        $this->softwareType->refresh();

        expect($this->softwareType->excludedFromRights()->count())->toBe(2);
    });
});
