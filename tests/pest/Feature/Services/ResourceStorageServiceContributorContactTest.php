<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ResourceStorageService – Contributor Contact Person email/website', function () {
    beforeEach(function () {
        $this->service = app(ResourceStorageService::class);
        $this->user = User::factory()->create();

        if (TitleType::where('slug', 'MainTitle')->doesntExist()) {
            $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
        }
        if (ResourceType::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
        }

        // Ensure Contact Person contributor type exists
        ContributorType::firstOrCreate(
            ['slug' => 'ContactPerson'],
            ['name' => 'Contact Person', 'category' => 'person'],
        );
    });

    /**
     * Build minimal resource data with a person contributor.
     *
     * @param  array<string, mixed>  $contributorOverrides
     * @return array<string, mixed>
     */
    function contributorResourceData(array $contributorOverrides = []): array
    {
        return [
            'resourceId' => null,
            'year' => 2025,
            'resourceType' => ResourceType::first()->id,
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'position' => 0,
                ],
            ],
            'contributors' => [
                array_merge([
                    'type' => 'person',
                    'firstName' => 'Contact',
                    'lastName' => 'Person',
                    'roles' => ['Contact Person'],
                    'email' => 'contact@example.org',
                    'website' => 'https://example.org',
                    'affiliations' => [],
                    'position' => 0,
                ], $contributorOverrides),
            ],
        ];
    }

    it('stores email and website when contributor has Contact Person role', function () {
        $data = contributorResourceData();

        [$resource] = $this->service->store($data, $this->user->id);

        $contributor = $resource->contributors()->first();
        expect($contributor->email)->toBe('contact@example.org')
            ->and($contributor->website)->toBe('https://example.org');
    });

    it('stores null email and website when contributor has no Contact Person role', function () {
        $data = contributorResourceData([
            'roles' => ['DataCollector'],
            'email' => 'should-be-ignored@example.org',
            'website' => 'https://should-be-ignored.org',
        ]);

        // Ensure DataCollector type exists
        ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector', 'category' => 'person'],
        );

        [$resource] = $this->service->store($data, $this->user->id);

        $contributor = $resource->contributors()->first();
        expect($contributor->email)->toBeNull()
            ->and($contributor->website)->toBeNull();
    });

    it('stores email but null website when website is not provided', function () {
        $data = contributorResourceData([
            'email' => 'contact@example.org',
            'website' => null,
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        $contributor = $resource->contributors()->first();
        expect($contributor->email)->toBe('contact@example.org')
            ->and($contributor->website)->toBeNull();
    });

    it('detects Contact Person role case-insensitively', function () {
        $data = contributorResourceData([
            'roles' => ['contact person'],
            'email' => 'lower@example.org',
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        $contributor = $resource->contributors()->first();
        expect($contributor->email)->toBe('lower@example.org');
    });

    it('clears email and website when updating contributor without Contact Person role', function () {
        // First: store with Contact Person
        $data = contributorResourceData();
        [$resource] = $this->service->store($data, $this->user->id);

        // Second: update same resource without Contact Person role
        ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector', 'category' => 'person'],
        );

        $updateData = contributorResourceData([
            'roles' => ['DataCollector'],
            'email' => 'stale@example.org',
            'website' => 'https://stale.org',
        ]);
        $updateData['resourceId'] = $resource->id;

        [$updatedResource] = $this->service->store($updateData, $this->user->id);

        $contributor = $updatedResource->contributors()->first();
        expect($contributor->email)->toBeNull()
            ->and($contributor->website)->toBeNull();
    });

    it('handles missing roles key gracefully', function () {
        $data = contributorResourceData();
        // Remove the roles key entirely from contributor data
        unset($data['contributors'][0]['roles']);

        [$resource] = $this->service->store($data, $this->user->id);

        $contributor = $resource->contributors()->first();
        expect($contributor->email)->toBeNull()
            ->and($contributor->website)->toBeNull();
    });
});
