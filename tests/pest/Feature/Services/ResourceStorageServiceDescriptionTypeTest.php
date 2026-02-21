<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

describe('ResourceStorageService – Description Type Mapping (Issue #547)', function () {
    beforeEach(function () {
        $this->service = app(ResourceStorageService::class);
        $this->user = User::factory()->create();

        // Seed required lookup tables
        if (TitleType::where('slug', 'MainTitle')->doesntExist()) {
            $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
        }
        if (ResourceType::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
        }
        $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);

        $this->resourceType = ResourceType::first();
    });

    /**
     * Helper to build minimal valid resource data with a specific description type.
     *
     * @param  array<int, array{descriptionType: string, description: string}>  $descriptions
     * @return array<string, mixed>
     */
    function buildResourceData(ResourceType $resourceType, array $descriptions): array
    {
        return [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
            ],
            'descriptions' => $descriptions,
        ];
    }

    // --- Multi-word description types (previously broken) ---

    it('stores description with kebab-case type "technical-info"', function () {
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'technical-info', 'description' => 'Technical details about the dataset.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('Technical details about the dataset.')
            ->and($description->descriptionType->slug)->toBe('TechnicalInfo');
    });

    it('stores description with kebab-case type "series-information"', function () {
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'series-information', 'description' => 'Part of a series.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('Part of a series.')
            ->and($description->descriptionType->slug)->toBe('SeriesInformation');
    });

    it('stores description with kebab-case type "table-of-contents"', function () {
        $data = buildResourceData($this->resourceType, [
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
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'abstract', 'description' => 'An abstract.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Abstract');
    });

    it('stores description with type "methods"', function () {
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'methods', 'description' => 'We used XRD analysis.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Methods');
    });

    it('stores description with type "other"', function () {
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'other', 'description' => 'Additional notes.'],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->descriptions->first()->descriptionType->slug)->toBe('Other');
    });

    // --- All six types at once ---

    it('stores all six description types simultaneously', function () {
        $data = buildResourceData($this->resourceType, [
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
        $data = buildResourceData($this->resourceType, [
            ['descriptionType' => 'nonexistent-type', 'description' => 'This should fail.'],
        ]);

        (void) $this->service->store($data, $this->user->id);
    })->throws(ValidationException::class);
});
