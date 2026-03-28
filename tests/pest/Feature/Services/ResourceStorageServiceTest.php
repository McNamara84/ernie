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

    it('stores classificationCode for controlled keywords', function () {
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
                    'firstName' => 'Jane',
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
                    'id' => '310607',
                    'text' => 'Nanobiotechnology',
                    'scheme' => 'ANZSRC Fields of Research',
                    'schemeURI' => 'https://www.abs.gov.au/statistics/classifications/australian-and-new-zealand-standard-research-classification-anzsrc',
                    'classificationCode' => '310607',
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($resource->subjects()->count())->toBe(1);
        $subject = $resource->subjects->first();
        expect($subject->value)->toBe('Nanobiotechnology')
            ->and($subject->subject_scheme)->toBe('ANZSRC Fields of Research')
            ->and($subject->classification_code)->toBe('310607')
            ->and($subject->value_uri)->toBe('310607');
    });
});

describe('ResourceStorageService - Issue #371: Date Created Handling', function () {
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
        $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    });

    it('uses imported created date when provided for new resources', function () {
        $resourceType = ResourceType::first();
        $importedDate = '2023-05-15';

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
            ],
            'importedCreatedDate' => $importedDate,
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        // Find the 'created' date
        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($importedDate)
            ->and($isUpdate)->toBeFalse();
    });

    it('uses current date as fallback when no imported created date is provided', function () {
        $resourceType = ResourceType::first();
        $today = now()->format('Y-m-d');

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
            ],
            // No importedCreatedDate provided
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($today);
    });

    it('preserves existing created date on resource update', function () {
        $resourceType = ResourceType::first();
        $originalDate = '2021-03-01';

        // Create initial resource with an imported date
        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Original Title', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
            ],
            'importedCreatedDate' => $originalDate,
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);
        $originalResourceId = $resource->id;

        // Update the resource (even with a new importedCreatedDate, it should be ignored)
        $updateData = [
            'resourceId' => $originalResourceId,
            'year' => 2025,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Updated Title', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Smith', 'position' => 0],
            ],
            'importedCreatedDate' => '2026-01-01', // This should be ignored on update
        ];

        [$updatedResource, $wasUpdate] = $this->service->store($updateData, $this->user->id);

        $createdDate = $updatedResource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($wasUpdate)->toBeTrue()
            ->and($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($originalDate); // Original date preserved
    });

    it('handles empty string as no imported date', function () {
        $resourceType = ResourceType::first();
        $today = now()->format('Y-m-d');

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'John', 'lastName' => 'Doe', 'position' => 0],
            ],
            'importedCreatedDate' => '', // Empty string should fall back to current date
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($today);
    });

    describe('Instruments', function () {
        it('stores instruments when creating a resource', function () {
            $resourceType = ResourceType::first();

            $data = [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Instrument Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
                'instruments' => [
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/INST001',
                        'pidType' => 'Handle',
                        'name' => 'Seismometer STS-2',
                    ],
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/INST002',
                        'pidType' => 'Handle',
                        'name' => 'GPS Receiver LEICA',
                    ],
                ],
            ];

            [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

            expect($isUpdate)->toBeFalse()
                ->and($resource->instruments()->count())->toBe(2);

            $instruments = $resource->instruments()->orderBy('position')->get();

            expect($instruments[0]->instrument_pid)->toBe('http://hdl.handle.net/21.12132/INST001')
                ->and($instruments[0]->instrument_pid_type)->toBe('Handle')
                ->and($instruments[0]->instrument_name)->toBe('Seismometer STS-2')
                ->and($instruments[0]->position)->toBe(0)
                ->and($instruments[1]->instrument_pid)->toBe('http://hdl.handle.net/21.12132/INST002')
                ->and($instruments[1]->instrument_name)->toBe('GPS Receiver LEICA')
                ->and($instruments[1]->position)->toBe(1);
        });

        it('replaces instruments when updating a resource', function () {
            $resourceType = ResourceType::first();

            // Create initial resource with instruments
            $data = [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Instrument Update Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
                'instruments' => [
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/OLD001',
                        'pidType' => 'Handle',
                        'name' => 'Old Instrument',
                    ],
                ],
            ];

            [$resource, $isUpdate] = $this->service->store($data, $this->user->id);
            expect($resource->instruments()->count())->toBe(1);

            // Update with different instruments
            $updateData = [
                'resourceId' => $resource->id,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Instrument Update Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
                'instruments' => [
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/NEW001',
                        'pidType' => 'Handle',
                        'name' => 'New Instrument A',
                    ],
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/NEW002',
                        'pidType' => 'Handle',
                        'name' => 'New Instrument B',
                    ],
                ],
            ];

            [$updatedResource, $wasUpdate] = $this->service->store($updateData, $this->user->id);

            expect($wasUpdate)->toBeTrue()
                ->and($updatedResource->instruments()->count())->toBe(2);

            $instruments = $updatedResource->instruments()->orderBy('position')->get();

            expect($instruments[0]->instrument_pid)->toBe('http://hdl.handle.net/21.12132/NEW001')
                ->and($instruments[1]->instrument_pid)->toBe('http://hdl.handle.net/21.12132/NEW002');

            // Old instrument should be gone
            expect(\App\Models\ResourceInstrument::where('instrument_pid', 'http://hdl.handle.net/21.12132/OLD001')->exists())->toBeFalse();
        });

        it('skips instruments with empty pid or name', function () {
            $resourceType = ResourceType::first();

            $data = [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Skip Invalid Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
                'instruments' => [
                    [
                        'pid' => '',
                        'pidType' => 'Handle',
                        'name' => 'No PID Instrument',
                    ],
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/VALID',
                        'pidType' => 'Handle',
                        'name' => '',
                    ],
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/GOOD',
                        'pidType' => 'Handle',
                        'name' => 'Valid Instrument',
                    ],
                ],
            ];

            [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

            // Only the valid instrument should be stored
            expect($resource->instruments()->count())->toBe(1)
                ->and($resource->instruments->first()->instrument_pid)->toBe('http://hdl.handle.net/21.12132/GOOD');
        });

        it('defaults pidType to Handle when not provided', function () {
            $resourceType = ResourceType::first();

            $data = [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Default PID Type Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
                'instruments' => [
                    [
                        'pid' => 'http://hdl.handle.net/21.12132/NOTYPE',
                        'name' => 'Instrument Without PID Type',
                    ],
                ],
            ];

            [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

            expect($resource->instruments()->count())->toBe(1)
                ->and($resource->instruments->first()->instrument_pid_type)->toBe('Handle');
        });

        it('stores no instruments when instruments key is absent', function () {
            $resourceType = ResourceType::first();

            $data = [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'No Instruments Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
                ],
            ];

            [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

            expect($resource->instruments()->count())->toBe(0);
        });
    });

    // =========================================================================
    // Funding References – funder_identifier_type_id regression tests
    // =========================================================================

    describe('Funding References', function () {
        beforeEach(function () {
            $this->artisan('db:seed', ['--class' => 'FunderIdentifierTypeSeeder']);
        });

        it('stores funder_identifier_type_id correctly for ROR funders', function () {
            $resourceType = ResourceType::first();
            $rorType = \App\Models\FunderIdentifierType::where('name', 'ROR')->first();

            $data = [
                'resourceId' => null,
                'year' => 2025,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'ROR Funder Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Alice', 'lastName' => 'Test', 'position' => 0],
                ],
                'fundingReferences' => [
                    [
                        'funderName' => 'Deutsche Forschungsgemeinschaft',
                        'funderIdentifier' => 'https://ror.org/018mejw64',
                        'funderIdentifierType' => 'ROR',
                        'awardNumber' => 'DFG-2025-001',
                        'awardUri' => '',
                        'awardTitle' => '',
                    ],
                ],
            ];

            [$resource] = $this->service->store($data, $this->user->id);

            $funding = $resource->fundingReferences()->first();
            expect($funding->funder_name)->toBe('Deutsche Forschungsgemeinschaft')
                ->and($funding->funder_identifier)->toBe('https://ror.org/018mejw64')
                ->and($funding->funder_identifier_type_id)->toBe($rorType->id)
                ->and($funding->scheme_uri)->toBe('https://ror.org/');
        });

        it('stores funder_identifier_type_id correctly for Crossref Funder ID', function () {
            $resourceType = ResourceType::first();
            $crossrefType = \App\Models\FunderIdentifierType::where('name', 'Crossref Funder ID')->first();

            $data = [
                'resourceId' => null,
                'year' => 2025,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'Crossref Funder Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Bob', 'lastName' => 'Test', 'position' => 0],
                ],
                'fundingReferences' => [
                    [
                        'funderName' => 'European Commission',
                        'funderIdentifier' => 'https://doi.org/10.13039/501100000780',
                        'funderIdentifierType' => 'Crossref Funder ID',
                        'awardNumber' => 'EC-2025-001',
                        'awardUri' => '',
                        'awardTitle' => '',
                    ],
                ],
            ];

            [$resource] = $this->service->store($data, $this->user->id);

            $funding = $resource->fundingReferences()->first();
            expect($funding->funder_identifier_type_id)->toBe($crossrefType->id)
                ->and($funding->scheme_uri)->toBe('https://doi.org/10.13039/');
        });

        it('stores null funder_identifier_type_id when no type is provided', function () {
            $resourceType = ResourceType::first();

            $data = [
                'resourceId' => null,
                'year' => 2025,
                'resourceType' => $resourceType->id,
                'titles' => [
                    ['title' => 'No Type Test', 'titleType' => 'MainTitle'],
                ],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Carol', 'lastName' => 'Test', 'position' => 0],
                ],
                'fundingReferences' => [
                    [
                        'funderName' => 'Generic Funder',
                        'funderIdentifier' => '',
                        'funderIdentifierType' => null,
                        'awardNumber' => '',
                        'awardUri' => '',
                        'awardTitle' => '',
                    ],
                ],
            ];

            [$resource] = $this->service->store($data, $this->user->id);

            $funding = $resource->fundingReferences()->first();
            expect($funding->funder_identifier_type_id)->toBeNull()
                ->and($funding->scheme_uri)->toBeNull();
        });
    });
});
