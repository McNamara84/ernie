<?php

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ResourceStorageService', function () {
    beforeEach(function () {
        $this->service = app(ResourceStorageService::class);
        $this->user = User::factory()->create();

        // Ensure basic seed data exists
        if (TitleType::where('slug', 'MainTitle')->doesntExist()) {
            $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
        }
        if (ResourceType::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
        }
        // Seed DescriptionType for descriptions tests
        $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    });

    it('creates a new resource with minimal data', function () {
        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Test Resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Test abstract description.',
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($resource)->toBeInstanceOf(Resource::class)
            ->and($resource->id)->toBeInt()
            ->and($isUpdate)->toBeFalse()
            ->and($resource->publication_year)->toBe(2024)
            ->and($resource->created_by_user_id)->toBe($this->user->id);

        // Check titles
        expect($resource->titles()->count())->toBe(1);
        $title = $resource->titles->first();
        expect($title->value)->toBe('Test Resource');

        // Check creators
        expect($resource->creators()->count())->toBe(1);
        $creator = $resource->creators->first();
        expect($creator->creatorable->family_name)->toBe('Doe')
            ->and($creator->creatorable->given_name)->toBe('John');

        // Check descriptions
        expect($resource->descriptions()->count())->toBe(1);
        $description = $resource->descriptions->first();
        expect($description->value)->toBe('Test abstract description.');
    });

    it('updates an existing resource', function () {
        $resourceType = ResourceType::first();

        // Create initial resource
        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Original Title',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Smith',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Original abstract.',
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);
        expect($isUpdate)->toBeFalse();

        // Update the resource
        $updateData = [
            'resourceId' => $resource->id,
            'year' => 2025,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Updated Title',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Smith',
                    'position' => 0,
                ],
                [
                    'type' => 'person',
                    'firstName' => 'Bob',
                    'lastName' => 'Jones',
                    'position' => 1,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Updated abstract.',
                ],
            ],
        ];

        [$updatedResource, $isUpdate] = $this->service->store($updateData, $this->user->id);

        expect($isUpdate)->toBeTrue()
            ->and($updatedResource->id)->toBe($resource->id)
            ->and($updatedResource->publication_year)->toBe(2025)
            ->and($updatedResource->updated_by_user_id)->toBe($this->user->id);

        // Check updated titles
        expect($updatedResource->titles()->count())->toBe(1);
        $title = $updatedResource->titles->first();
        expect($title->value)->toBe('Updated Title');

        // Check updated creators (should have 2 now)
        expect($updatedResource->creators()->count())->toBe(2);

        // Check updated descriptions
        $description = $updatedResource->descriptions->first();
        expect($description->value)->toBe('Updated abstract.');
    });

    it('stores licenses correctly', function () {
        $resourceType = ResourceType::first();

        // Create a test license
        $license = \App\Models\Right::factory()->create([
            'identifier' => 'test-license',
            'name' => 'Test License',
        ]);

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Test Resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Test abstract.',
                ],
            ],
            'licenses' => ['test-license'],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($resource->rights()->count())->toBe(1);
        $right = $resource->rights->first();
        expect($right->identifier)->toBe('test-license');
    });

    it('stores free keywords', function () {
        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Test Resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Test abstract.',
                ],
            ],
            'freeKeywords' => ['keyword1', 'keyword2', 'keyword3'],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($resource->subjects()->count())->toBe(3);
        $keywords = $resource->subjects->pluck('value')->all();
        expect($keywords)->toContain('keyword1', 'keyword2', 'keyword3');
    });

    it('stores controlled GCMD keywords', function () {
        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Test Resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Test abstract.',
                ],
            ],
            'gcmdKeywords' => [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
                    'text' => 'Test GCMD Keyword',
                    'scheme' => 'Science Keywords',
                    'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($resource->subjects()->count())->toBe(1);
        $subject = $resource->subjects->first();
        expect($subject->value)->toBe('Test GCMD Keyword')
            ->and($subject->subject_scheme)->toBe('Science Keywords')
            ->and($subject->value_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid');
    });
});
