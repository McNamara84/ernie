<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

describe('ResourceStorageService – Description Type Mapping (Issue #547)', function () {
    beforeEach(function () {
        $this->service = app(ResourceStorageService::class);
        $this->user = User::factory()->create();

        // Seed required lookup tables unconditionally (RefreshDatabase ensures empty DB)
        $this->seed(\Database\Seeders\TitleTypeSeeder::class);
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\DescriptionTypeSeeder::class);
        Right::create(['identifier' => 'CC-BY-4.0', 'name' => 'Creative Commons Attribution 4.0']);

        $this->resourceType = ResourceType::first();

        // Closure helper to build valid resource data with specific descriptions.
        // Using a closure instead of a named function avoids global namespace
        // pollution and potential redeclare errors in watch mode.
        $this->buildResourceData = function (array $descriptions): array {
            return [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $this->resourceType->id,
                'titles' => [
                    ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
                ],
                'licenses' => ['CC-BY-4.0'],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
                ],
                'descriptions' => $descriptions,
            ];
        };
    });

    // --- Multi-word description types (previously broken) ---

    it('stores description with kebab-case type "technical-info"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'technical-info', 'description' => 'Technical details about the dataset.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('Technical details about the dataset.')
            ->and($description->descriptionType->slug)->toBe('TechnicalInfo');
    });

    it('stores description with kebab-case type "series-information"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'series-information', 'description' => 'Part of a series.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('Part of a series.')
            ->and($description->descriptionType->slug)->toBe('SeriesInformation');
    });

    it('stores description with kebab-case type "table-of-contents"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'table-of-contents', 'description' => '1. Introduction 2. Methods'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('1. Introduction 2. Methods')
            ->and($description->descriptionType->slug)->toBe('TableOfContents');
    });

    // --- Single-word description types (always worked) ---

    it('stores description with type "abstract"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'abstract', 'description' => 'An abstract.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Abstract');
    });

    it('stores description with type "methods"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'methods', 'description' => 'We used XRD analysis.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Methods');
    });

    it('stores description with type "other"', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'other', 'description' => 'Additional notes.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Other');
    });

    // --- All six types at once ---

    it('stores all six description types simultaneously', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'abstract', 'description' => 'Abstract text.'],
            ['descriptionType' => 'methods', 'description' => 'Methods text.'],
            ['descriptionType' => 'series-information', 'description' => 'Series text.'],
            ['descriptionType' => 'table-of-contents', 'description' => 'TOC text.'],
            ['descriptionType' => 'technical-info', 'description' => 'Technical text.'],
            ['descriptionType' => 'other', 'description' => 'Other text.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(6);

        $slugs = $resource->descriptions->map(fn (Description $d) => $d->descriptionType->slug)->sort()->values()->all();
        expect($slugs)->toBe(['Abstract', 'Methods', 'Other', 'SeriesInformation', 'TableOfContents', 'TechnicalInfo']);
    });

    // --- Error handling ---

    it('throws ValidationException for unknown description type', function () {
        $data = ($this->buildResourceData)([
            ['descriptionType' => 'nonexistent-type', 'description' => 'This should fail.'],
        ]);

        (void) $this->service->store($data, $this->user->id);
    })->throws(ValidationException::class);
});
