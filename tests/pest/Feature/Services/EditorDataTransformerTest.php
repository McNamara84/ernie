<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Services\Editor\EditorDataTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Seed description types
    $this->seed(\Database\Seeders\DescriptionTypeSeeder::class);
});

describe('EditorDataTransformer', function (): void {
    describe('transformDescriptions', function (): void {
        it('correctly maps PascalCase description type slugs to frontend format', function (): void {
            // Arrange: Create a resource with a description that has an "Abstract" type
            $resource = Resource::factory()->create();

            // Get the Abstract description type (stored with PascalCase slug "Abstract")
            $abstractType = DescriptionType::where('slug', 'Abstract')->first();
            expect($abstractType)->not->toBeNull('Abstract description type should exist in database');

            // Create a description with the Abstract type
            Description::create([
                'resource_id' => $resource->id,
                'description_type_id' => $abstractType->id,
                'value' => 'Test abstract content',
            ]);

            // Refresh to load the relationship
            $resource->refresh();
            $resource->load('descriptions.descriptionType');

            // Act: Transform the resource
            $transformer = new EditorDataTransformer;
            $result = $transformer->transformDescriptions($resource);

            // Assert: The type should be "Abstract" (not "Other")
            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('Abstract');
            expect($result[0]['description'])->toBe('Test abstract content');
        });

        it('correctly maps SeriesInformation type (kebab-case conversion)', function (): void {
            $resource = Resource::factory()->create();

            $seriesInfoType = DescriptionType::where('slug', 'SeriesInformation')->first();
            expect($seriesInfoType)->not->toBeNull('SeriesInformation description type should exist');

            Description::create([
                'resource_id' => $resource->id,
                'description_type_id' => $seriesInfoType->id,
                'value' => 'Test series info',
            ]);

            $resource->refresh();
            $resource->load('descriptions.descriptionType');

            $transformer = new EditorDataTransformer;
            $result = $transformer->transformDescriptions($resource);

            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('SeriesInformation');
        });

        it('falls back to Other for unknown description types', function (): void {
            $resource = Resource::factory()->create();

            $otherType = DescriptionType::where('slug', 'Other')->first();
            expect($otherType)->not->toBeNull('Other description type should exist');

            Description::create([
                'resource_id' => $resource->id,
                'description_type_id' => $otherType->id,
                'value' => 'Test other content',
            ]);

            $resource->refresh();
            $resource->load('descriptions.descriptionType');

            $transformer = new EditorDataTransformer;
            $result = $transformer->transformDescriptions($resource);

            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('Other');
        });
    });
});
