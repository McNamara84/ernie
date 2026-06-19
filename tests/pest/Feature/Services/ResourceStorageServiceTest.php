<?php

declare(strict_types=1);

use App\Models\FunderIdentifierType;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceInstrument;
use App\Models\ResourceRight;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\KeywordSuggestionService;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        if (IdentifierType::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
        }
        if (RelationType::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
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

    it('stores imported raw rights without a selected catalog license', function () {
        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Imported rights resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'licenses' => [],
            'rawRights' => [
                [
                    'rights' => 'CC BY 4.0',
                    'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
                    'source' => 'xml-upload',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Ada',
                    'lastName' => 'Lovelace',
                    'position' => 0,
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Imported rights should remain available for SPDX review.',
                ],
            ],
        ];

        [$resource] = $this->service->store($data, $this->user->id);

        $resourceRight = ResourceRight::where('resource_id', $resource->id)->first();

        expect($resourceRight)->not->toBeNull()
            ->and($resourceRight->rights_id)->toBeNull()
            ->and($resourceRight->rights_text)->toBe('CC BY 4.0')
            ->and($resourceRight->rights_uri)->toBe('http://creativecommons.org/licenses/by/4.0')
            ->and($resourceRight->source)->toBe('xml-upload');
    });

    it('recreates MainTitle title type when storing a resource and the lookup row is missing', function () {
        TitleType::query()->delete();
        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Recovered Main Title Resource',
                    'titleType' => 'MainTitle',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Recovery',
                    'position' => 0,
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);
        $mainTitleType = TitleType::where('slug', 'MainTitle')->firstOrFail();

        expect($isUpdate)->toBeFalse()
            ->and($mainTitleType->name)->toBe('Main Title')
            ->and($resource->titles()->sole()->title_type_id)->toBe($mainTitleType->id);
    });

    it('stores editor title type slugs using the matching DataCite title type', function () {
        $resourceType = ResourceType::first();
        $alternativeTitleType = TitleType::where('slug', 'AlternativeTitle')->firstOrFail();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Recovered Alternative Title Resource',
                    'titleType' => 'alternative-title',
                ],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Recovery',
                    'position' => 0,
                ],
            ],
        ];

        [$resource, $isUpdate] = $this->service->store($data, $this->user->id);

        expect($isUpdate)->toBeFalse()
            ->and($resource->titles()->sole()->title_type_id)->toBe($alternativeTitleType->id);
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
        $license = Right::factory()->create([
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

    it('invalidates cached thesaurus facets after a subject-only update', function () {
        Storage::fake('local');
        Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
            'lastUpdated' => now()->toIso8601String(),
            'data' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [[
                    'id' => 'science-gnss',
                    'text' => 'GNSS',
                    'language' => 'en',
                    'scheme' => 'Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $resourceType = ResourceType::first();

        $initialData = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Controlled Keyword Resource',
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
                    'id' => 'science-gnss',
                    'text' => 'GNSS',
                    'scheme' => 'Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                ],
            ],
        ];

        [$resource] = $this->service->store($initialData, $this->user->id);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $keywordService = app(KeywordSuggestionService::class);

        expect($keywordService->getThesaurusFacets())->not->toBe([]);

        $updateData = [
            'resourceId' => $resource->id,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Controlled Keyword Resource',
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
            'gcmdKeywords' => [],
        ];

        [$updatedResource, $wasUpdate] = $this->service->store($updateData, $this->user->id);

        expect($wasUpdate)->toBeTrue()
            ->and($updatedResource->id)->toBe($resource->id);

        expect($keywordService->getThesaurusFacets())->toBe([]);
    });

    it('stores related identifiers with resolved citation labels and trimmed relation details', function () {
        $resourceType = ResourceType::first();

        $mock = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $mock->shouldReceive('resolveBestEffort')
            ->once()
            ->with('10.5880/test.related', 'DOI', Mockery::type('float'))
            ->andReturn('Doe, J. (2026): Auto-resolved citation.');
        $this->app->instance(RelatedIdentifierCitationLabelService::class, $mock);

        $service = app(ResourceStorageService::class);

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
            'relatedIdentifiers' => [
                [
                    'identifier' => ' 10.5880/test.related ',
                    'identifierType' => 'DOI',
                    'relationType' => 'Cites',
                    'relationTypeInformation' => '  Supplemental context  ',
                ],
                [
                    'identifier' => '   ',
                    'identifierType' => 'URL',
                    'relationType' => 'References',
                ],
            ],
        ];

        [$resource] = $service->store($data, $this->user->id);

        $resource->refresh()->load('relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType');

        expect($resource->relatedIdentifiers)->toHaveCount(1);

        $related = $resource->relatedIdentifiers->sole();

        expect($related->identifier)->toBe('10.5880/test.related')
            ->and($related->identifierType?->slug)->toBe('DOI')
            ->and($related->relationType?->slug)->toBe('Cites')
            ->and($related->relation_type_information)->toBe('Supplemental context')
            ->and($related->citation_label)->toBe('Doe, J. (2026): Auto-resolved citation.')
            ->and($related->position)->toBe(0);
    });

    it('preserves manual related identifier citation labels without calling the resolver', function () {
        $resourceType = ResourceType::first();

        $mock = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $mock->shouldNotReceive('resolveBestEffort');
        $this->app->instance(RelatedIdentifierCitationLabelService::class, $mock);

        $service = app(ResourceStorageService::class);

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
            'relatedIdentifiers' => [
                [
                    'identifier' => '10.5880/test.manual',
                    'identifierType' => 'DOI',
                    'relationType' => 'References',
                    'relationTypeInformation' => '   ',
                    'citationLabel' => '  Manual citation label  ',
                ],
            ],
        ];

        [$resource] = $service->store($data, $this->user->id);

        $related = $resource->fresh()->relatedIdentifiers()->sole();

        expect($related->citation_label)->toBe('Manual citation label')
            ->and($related->relation_type_information)->toBeNull();
    });

    it('coerces non-array related identifiers to an empty list before storage', function () {
        $resourceType = ResourceType::first();

        $mock = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $mock->shouldNotReceive('resolveBestEffort');
        $this->app->instance(RelatedIdentifierCitationLabelService::class, $mock);

        $service = app(ResourceStorageService::class);

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
            'relatedIdentifiers' => 'not-an-array',
        ];

        [$resource] = $service->store($data, $this->user->id);

        expect($resource->relatedIdentifiers()->count())->toBe(0);
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
                    'path' => 'EARTH SCIENCE > SOLID EARTH > Test GCMD Keyword',
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
            ->and($subject->value_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid')
            ->and($subject->breadcrumb_path)->toBe('EARTH SCIENCE > SOLID EARTH > Test GCMD Keyword');
    });

    it('resolves legacy path-only controlled keywords before storing them', function () {
        Storage::fake('local');
        Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
            'data' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'biosphere',
                    'text' => 'BIOSPHERE',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forests-uri',
                        'text' => 'FORESTS',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://example.test/sciencekeywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $resourceType = ResourceType::first();

        $data = [
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                [
                    'title' => 'Legacy path keyword resource',
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
                    'id' => '',
                    'text' => 'EARTH SCIENCE &gt;  BIOSPHERE  &gt; FORESTS',
                    'path' => 'EARTH SCIENCE &gt;  BIOSPHERE  &gt; FORESTS',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                ],
            ],
        ];

        [$resource] = $this->service->store($data, $this->user->id);

        $subject = $resource->subjects()->sole();

        expect($subject->value)->toBe('EARTH SCIENCE > BIOSPHERE > FORESTS')
            ->and($subject->subject_scheme)->toBe('Science Keywords')
            ->and($subject->scheme_uri)->toBe('https://example.test/sciencekeywords')
            ->and($subject->value_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/forests-uri')
            ->and($subject->breadcrumb_path)->toBe('EARTH SCIENCE > BIOSPHERE > FORESTS');
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
                    'path' => 'Natural Sciences > Biological Sciences > Nanobiotechnology',
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
            ->and($subject->value_uri)->toBeNull()
            ->and($subject->breadcrumb_path)->toBe('Natural Sciences > Biological Sciences > Nanobiotechnology');
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

    it('stores explicit Collected periods as start and end dates', function () {
        $resourceType = ResourceType::first();

        [$resource] = $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Collected Period Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Collected',
                    'dateMode' => 'range',
                    'startDate' => '2024-01-01',
                    'endDate' => '2024-12-31',
                ],
            ],
        ], $this->user->id);

        $collectedDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['collected']);
        })->first();

        expect($collectedDate)->not->toBeNull()
            ->and($collectedDate->date_value)->toBeNull()
            ->and($collectedDate->start_date)->toBe('2024-01-01')
            ->and($collectedDate->end_date)->toBe('2024-12-31');
    });

    it('stores explicit single dates as date_value when only a start date is provided', function () {
        $resourceType = ResourceType::first();

        [$resource] = $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Available Single Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Available',
                    'dateMode' => 'single',
                    'startDate' => '2024-01-01',
                ],
            ],
        ], $this->user->id);

        $availableDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['available']);
        })->first();

        expect($availableDate)->not->toBeNull()
            ->and($availableDate->date_value)->toBe('2024-01-01')
            ->and($availableDate->start_date)->toBeNull()
            ->and($availableDate->end_date)->toBeNull();
    });

    it('normalizes date information when storing dates directly', function () {
        $resourceType = ResourceType::first();

        [$resource] = $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Date Information Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Available',
                    'dateMode' => 'single',
                    'startDate' => '2024-01-01',
                    'dateInformation' => '  Approximate availability date  ',
                ],
                [
                    'dateType' => 'Other',
                    'dateMode' => 'single',
                    'startDate' => '2024-02-01',
                    'dateInformation' => '   ',
                ],
            ],
        ], $this->user->id);

        $availableDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['available']);
        })->first();
        $otherDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['other']);
        })->first();

        expect($availableDate)->not->toBeNull()
            ->and($availableDate->date_information)->toBe('Approximate availability date')
            ->and($otherDate)->not->toBeNull()
            ->and($otherDate->date_information)->toBeNull();
    });

    it('rejects explicit range dates without an end date before storing', function () {
        $resourceType = ResourceType::first();

        expect(fn () => $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Incomplete Period Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Collected',
                    'dateMode' => 'range',
                    'startDate' => '2024-01-01',
                    'endDate' => null,
                ],
            ],
        ], $this->user->id))->toThrow(ValidationException::class);
    });

    it('rejects unsupported explicit range date types before storing', function () {
        $resourceType = ResourceType::first();

        expect(fn () => $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Unsupported Period Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Available',
                    'dateMode' => 'range',
                    'startDate' => '2024-01-01',
                    'endDate' => '2024-12-31',
                ],
            ],
        ], $this->user->id))->toThrow(ValidationException::class);
    });

    it('rejects unknown date modes before storing', function () {
        $resourceType = ResourceType::first();

        expect(fn () => $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Unknown Date Mode Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Collected',
                    'dateMode' => 'period',
                    'startDate' => '2024-01-01',
                    'endDate' => '2024-12-31',
                ],
            ],
        ], $this->user->id))->toThrow(ValidationException::class);
    });

    it('rejects non-range date types with start and end dates before storing', function () {
        $resourceType = ResourceType::first();

        expect(fn () => $this->service->store([
            'resourceId' => null,
            'year' => 2024,
            'resourceType' => $resourceType->id,
            'titles' => [
                ['title' => 'Legacy Unsupported Period Resource', 'titleType' => 'MainTitle'],
            ],
            'authors' => [
                ['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0],
            ],
            'dates' => [
                [
                    'dateType' => 'Available',
                    'startDate' => '2024-01-01',
                    'endDate' => '2024-12-31',
                ],
            ],
        ], $this->user->id))->toThrow(ValidationException::class);
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
            expect(ResourceInstrument::where('instrument_pid', 'http://hdl.handle.net/21.12132/OLD001')->exists())->toBeFalse();
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
            $rorType = FunderIdentifierType::where('name', 'ROR')->first();

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
            $crossrefType = FunderIdentifierType::where('name', 'Crossref Funder ID')->first();

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
