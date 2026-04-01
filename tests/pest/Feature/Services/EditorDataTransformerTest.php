<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\Right;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\Title;
use App\Services\Editor\EditorDataTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

covers(EditorDataTransformer::class);

beforeEach(function (): void {
    $this->transformer = new EditorDataTransformer;
    $this->resource = Resource::factory()->create(['publication_year' => 2025, 'version' => '1.0']);
});

// =========================================================================
// transformResource
// =========================================================================

describe('transformResource', function (): void {
    it('returns all expected top-level keys', function (): void {
        $this->resource->load([
            'titles.titleType', 'rights', 'creators.creatorable', 'creators.affiliations',
            'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'fundingReferences', 'language',
        ]);

        $result = $this->transformer->transformResource($this->resource);

        expect($result)->toHaveKeys([
            'doi', 'year', 'version', 'language', 'resourceType', 'resourceId',
            'titles', 'initialLicenses', 'authors', 'contributors',
            'descriptions', 'dates', 'gcmdKeywords', 'freeKeywords',
            'coverages', 'relatedWorks', 'fundingReferences', 'mslLaboratories',
        ]);
    });

    it('returns correct scalar values', function (): void {
        $this->resource->load([
            'titles.titleType', 'rights', 'creators.creatorable', 'creators.affiliations',
            'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'fundingReferences', 'language',
        ]);

        $result = $this->transformer->transformResource($this->resource);

        expect($result['year'])->toBe('2025')
            ->and($result['version'])->toBe('1.0')
            ->and($result['resourceId'])->toBe((string) $this->resource->id);
    });

    it('returns empty string for null doi', function (): void {
        $resource = Resource::factory()->create(['doi' => null]);
        $resource->load([
            'titles.titleType', 'rights', 'creators.creatorable', 'creators.affiliations',
            'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'fundingReferences', 'language',
        ]);

        $result = $this->transformer->transformResource($resource);

        expect($result['doi'])->toBe('');
    });

    it('returns empty string for null version', function (): void {
        $resource = Resource::factory()->create(['version' => null]);
        $resource->load([
            'titles.titleType', 'rights', 'creators.creatorable', 'creators.affiliations',
            'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes',
            'descriptions.descriptionType', 'dates.dateType', 'subjects', 'geoLocations',
            'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'fundingReferences', 'language',
        ]);

        $result = $this->transformer->transformResource($resource);

        expect($result['version'])->toBe('');
    });
});

// =========================================================================
// transformTitles
// =========================================================================

describe('transformTitles', function (): void {
    it('transforms main title correctly', function (): void {
        Title::factory()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Test Dataset Title',
        ]);
        $this->resource->load('titles.titleType');

        $result = $this->transformer->transformTitles($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['title'])->toBe('Test Dataset Title')
            ->and($result[0]['titleType'])->toBe('main-title');
    });

    it('transforms alternative title with kebab-case type', function (): void {
        Title::factory()->alternativeTitle()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Alt Title',
        ]);
        $this->resource->load('titles.titleType');

        $result = $this->transformer->transformTitles($this->resource);

        expect($result[0]['titleType'])->toBe('alternative-title');
    });

    it('transforms subtitle correctly', function (): void {
        Title::factory()->subtitle()->create([
            'resource_id' => $this->resource->id,
            'value' => 'A Subtitle',
        ]);
        $this->resource->load('titles.titleType');

        $result = $this->transformer->transformTitles($this->resource);

        expect($result[0]['titleType'])->toBe('subtitle');
    });
});

// =========================================================================
// transformLicenses
// =========================================================================

describe('transformLicenses', function (): void {
    it('returns license identifiers', function (): void {
        $right = Right::factory()->ccBy4()->create();
        $this->resource->rights()->attach($right->id);
        $this->resource->load('rights');

        $result = $this->transformer->transformLicenses($this->resource);

        expect($result)->toBeArray()
            ->and($result[0])->toBe('CC-BY-4.0');
    });

    it('returns empty array when no licenses attached', function (): void {
        $this->resource->load('rights');

        $result = $this->transformer->transformLicenses($this->resource);

        expect($result)->toBeEmpty();
    });
});

// =========================================================================
// transformCreators
// =========================================================================

describe('transformCreators', function (): void {
    it('transforms person creator to author', function (): void {
        $person = Person::factory()->withOrcid('0000-0002-1825-0097')->create([
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'])->toHaveCount(1)
            ->and($result['authors'][0]['type'])->toBe('person')
            ->and($result['authors'][0]['firstName'])->toBe('John')
            ->and($result['authors'][0]['lastName'])->toBe('Doe')
            ->and($result['authors'][0]['orcid'])->toBeString()
            ->and($result['authors'][0]['orcidVerified'])->toBeTrue();
    });

    it('sets orcidVerified to true for creator with ORCID stored as URL', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Julia',
            'family_name' => 'URL',
            'name_identifier' => 'https://orcid.org/0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeTrue();
    });

    it('sets orcidVerified to true for creator with www.orcid.org URL', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Julia',
            'family_name' => 'WWW',
            'name_identifier' => 'https://www.orcid.org/0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeTrue();
    });

    it('sets orcidVerified to false for person creator without orcid', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Alice',
            'family_name' => 'Wonder',
            'name_identifier' => null,
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to true for person contributor with orcid', function (): void {
        $person = Person::factory()->withOrcid('0000-0002-1825-0097')->create([
            'given_name' => 'Bob',
            'family_name' => 'Builder',
        ]);

        ResourceContributor::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'][0]['orcidVerified'])->toBeTrue();
    });

    it('sets orcidVerified to false for person contributor without orcid', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Carol',
            'family_name' => 'Danvers',
            'name_identifier' => null,
        ]);

        ResourceContributor::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to false for creator with non-ORCID identifier scheme', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Eve',
            'family_name' => 'Torres',
            'name_identifier' => 'https://isni.org/isni/0000000121032683',
            'name_identifier_scheme' => 'ISNI',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to true for creator with null scheme (legacy data)', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Frank',
            'family_name' => 'Legacy',
            'name_identifier' => '0000-0001-2345-6789',
            'name_identifier_scheme' => null,
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeTrue();
    });

    it('sets orcidVerified to false for creator with whitespace-only identifier', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Grace',
            'family_name' => 'Hopper',
            'name_identifier' => '   ',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to false for contributor with non-ORCID identifier scheme', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Harold',
            'family_name' => 'Finch',
            'name_identifier' => 'https://isni.org/isni/0000000121032683',
            'name_identifier_scheme' => 'ISNI',
        ]);

        ResourceContributor::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to false for creator with invalid ORCID checksum', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Ivan',
            'family_name' => 'Badcheck',
            'name_identifier' => '0000-0002-1825-0000',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to false for creator with malformed ORCID string', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Malformed',
            'name_identifier' => 'not-an-orcid',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeFalse();
    });

    it('sets orcidVerified to true for creator with mixed-case scheme', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Kate',
            'family_name' => 'Case',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'Orcid',
        ]);

        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['orcidVerified'])->toBeTrue();
    });

    it('transforms institution creator to author', function (): void {
        $institution = Institution::factory()->withRor()->create(['name' => 'GFZ Potsdam']);

        ResourceCreator::factory()->forInstitution($institution)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'])->toHaveCount(1)
            ->and($result['authors'][0]['type'])->toBe('institution')
            ->and($result['authors'][0]['institutionName'])->toBe('GFZ Potsdam');
    });

    it('filters out MSL laboratories from authors', function (): void {
        $lab = Institution::factory()->create([
            'name' => 'Test Lab',
            'name_identifier' => 'lab-001',
            'name_identifier_scheme' => 'labid',
        ]);

        ResourceCreator::factory()->forInstitution($lab)->create([
            'resource_id' => $this->resource->id,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'])->toBeEmpty();
    });

    it('sorts authors by position', function (): void {
        $person1 = Person::factory()->create(['given_name' => 'Alice']);
        $person2 = Person::factory()->create(['given_name' => 'Bob']);

        ResourceCreator::factory()->forPerson($person2)->create([
            'resource_id' => $this->resource->id,
            'position' => 2,
        ]);
        ResourceCreator::factory()->forPerson($person1)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['firstName'])->toBe('Alice')
            ->and($result['authors'][1]['firstName'])->toBe('Bob');
    });

    it('collects unique affiliations from creators', function (): void {
        $person = Person::factory()->create();
        $creator = ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
        ]);

        Affiliation::create([
            'affiliatable_type' => ResourceCreator::class,
            'affiliatable_id' => $creator->id,
            'name' => 'GFZ Potsdam',
            'identifier' => 'https://ror.org/04z8jg394',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org',
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['authors'][0]['affiliations'])->toHaveCount(1)
            ->and($result['authors'][0]['affiliations'][0]['value'])->toBe('GFZ Potsdam')
            ->and($result['authors'][0]['affiliations'][0]['rorId'])->toBe('https://ror.org/04z8jg394');
    });

    it('transforms person contributor correctly', function (): void {
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Smith']);
        ResourceContributor::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'])->toHaveCount(1)
            ->and($result['contributors'][0]['type'])->toBe('person')
            ->and($result['contributors'][0]['firstName'])->toBe('Jane')
            ->and($result['contributors'][0]['lastName'])->toBe('Smith');
    });

    it('transforms institution contributor correctly', function (): void {
        $institution = Institution::factory()->create(['name' => 'Partner Institute']);
        ResourceContributor::factory()->forInstitution($institution)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'])->toHaveCount(1)
            ->and($result['contributors'][0]['type'])->toBe('institution')
            ->and($result['contributors'][0]['institutionName'])->toBe('Partner Institute');
    });

    it('includes contributor affiliations', function (): void {
        $person = Person::factory()->create();
        $contributor = ResourceContributor::factory()->forPerson($person)->create([
            'resource_id' => $this->resource->id,
        ]);

        Affiliation::create([
            'affiliatable_type' => ResourceContributor::class,
            'affiliatable_id' => $contributor->id,
            'name' => 'MIT',
            'identifier' => 'https://ror.org/042nb2s44',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org',
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations', 'contributors.contributorable', 'contributors.affiliations', 'contributors.contributorTypes']);

        $result = $this->transformer->transformCreators($this->resource);

        expect($result['contributors'][0]['affiliations'])->toHaveCount(1)
            ->and($result['contributors'][0]['affiliations'][0]['value'])->toBe('MIT');
    });
});

// =========================================================================
// transformDescriptions
// =========================================================================

describe('transformDescriptions', function (): void {
    beforeEach(function (): void {
        $this->seed(\Database\Seeders\DescriptionTypeSeeder::class);
    });

    it('correctly maps PascalCase slug Abstract to frontend format', function (): void {
        $abstractType = DescriptionType::where('slug', 'Abstract')->first();
        Description::create([
            'resource_id' => $this->resource->id,
            'description_type_id' => $abstractType->id,
            'value' => 'Test abstract content',
        ]);
        $this->resource->load('descriptions.descriptionType');

        $result = $this->transformer->transformDescriptions($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['type'])->toBe('Abstract')
            ->and($result[0]['description'])->toBe('Test abstract content');
    });

    it('correctly maps SeriesInformation type via kebab-case conversion', function (): void {
        $seriesInfoType = DescriptionType::where('slug', 'SeriesInformation')->first();
        Description::create([
            'resource_id' => $this->resource->id,
            'description_type_id' => $seriesInfoType->id,
            'value' => 'Test series info',
        ]);
        $this->resource->load('descriptions.descriptionType');

        $result = $this->transformer->transformDescriptions($this->resource);

        expect($result[0]['type'])->toBe('SeriesInformation');
    });

    it('correctly maps TechnicalInfo type', function (): void {
        $techType = DescriptionType::where('slug', 'TechnicalInfo')->first();
        Description::create([
            'resource_id' => $this->resource->id,
            'description_type_id' => $techType->id,
            'value' => 'Technical details',
        ]);
        $this->resource->load('descriptions.descriptionType');

        $result = $this->transformer->transformDescriptions($this->resource);

        expect($result[0]['type'])->toBe('TechnicalInfo');
    });

    it('falls back to Other for unknown description types', function (): void {
        $otherType = DescriptionType::where('slug', 'Other')->first();
        Description::create([
            'resource_id' => $this->resource->id,
            'description_type_id' => $otherType->id,
            'value' => 'Test other content',
        ]);
        $this->resource->load('descriptions.descriptionType');

        $result = $this->transformer->transformDescriptions($this->resource);

        expect($result[0]['type'])->toBe('Other');
    });
});

// =========================================================================
// transformDates
// =========================================================================

describe('transformDates', function (): void {
    it('transforms date with start and end values', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Collected', 'name' => 'Collected']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => '2024-01-15',
            'end_date' => '2024-06-30',
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['dateType'])->toBe('Collected')
            ->and($result[0]['startDate'])->toBe('2024-01-15')
            ->and($result[0]['endDate'])->toBe('2024-06-30');
    });

    it('excludes coverage, created, and updated date types', function (): void {
        foreach (['coverage', 'created', 'updated'] as $slug) {
            $dateType = DateType::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
            ResourceDate::create([
                'resource_id' => $this->resource->id,
                'date_type_id' => $dateType->id,
                'start_date' => '2024-01-01',
            ]);
        }
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result)->toBeEmpty();
    });

    it('preserves ISO 8601 datetime+timezone values', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Submitted', 'name' => 'Submitted']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => '2022-10-06T09:35+01:00',
            'end_date' => null,
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result[0]['startDate'])->toBe('2022-10-06T09:35+01:00')
            ->and($result[0]['endDate'])->toBe('');
    });

    it('preserves partial date precision (YYYY)', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Valid', 'name' => 'Valid']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => '2024',
            'end_date' => null,
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result[0]['startDate'])->toBe('2024');
    });

    it('preserves partial date precision (YYYY-MM)', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Available', 'name' => 'Available']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => '2024-06',
            'end_date' => null,
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result[0]['startDate'])->toBe('2024-06');
    });

    it('returns empty string for null date values', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Issued', 'name' => 'Issued']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => null,
            'end_date' => null,
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result[0]['startDate'])->toBe('')
            ->and($result[0]['endDate'])->toBe('');
    });

    it('returns empty string for unparseable date values', function (): void {
        $dateType = DateType::factory()->create(['slug' => 'Accepted', 'name' => 'Accepted']);
        ResourceDate::create([
            'resource_id' => $this->resource->id,
            'date_type_id' => $dateType->id,
            'start_date' => 'not-a-date',
            'end_date' => null,
        ]);
        $this->resource->load('dates.dateType');

        $result = $this->transformer->transformDates($this->resource);

        expect($result[0]['startDate'])->toBe('');
    });
});

// =========================================================================
// transformFreeKeywords / transformGcmdKeywords
// =========================================================================

describe('transformFreeKeywords', function (): void {
    it('returns only free text keywords (no scheme)', function (): void {
        Subject::factory()->create([
            'resource_id' => $this->resource->id,
            'value' => 'seismology',
            'subject_scheme' => null,
        ]);
        Subject::factory()->gcmd()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Earth Science',
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformFreeKeywords($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBe('seismology');
    });
});

describe('transformGcmdKeywords', function (): void {
    it('returns controlled keywords with scheme info', function (): void {
        Subject::factory()->gcmd()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Earthquakes',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/uuid-123',
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGcmdKeywords($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['text'])->toBe('Earthquakes')
            ->and($result[0]['id'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/uuid-123')
            ->and($result[0]['scheme'])->toBe('GCMD Science Keywords')
            ->and($result[0]['language'])->toBe('en');
    });

    it('excludes free text keywords', function (): void {
        Subject::factory()->create([
            'resource_id' => $this->resource->id,
            'value' => 'free keyword',
            'subject_scheme' => null,
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGcmdKeywords($this->resource);

        expect($result)->toBeEmpty();
    });

    it('returns sequential array keys when free keywords precede GCMD keywords', function (): void {
        // Create free-text keywords first (these will be filtered OUT, creating gaps in keys)
        Subject::factory()->count(5)->create([
            'resource_id' => $this->resource->id,
            'subject_scheme' => null,
        ]);
        // Create GCMD keywords after (their collection keys will be 5, 6, 7)
        Subject::factory()->gcmd()->count(3)->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGcmdKeywords($this->resource);

        expect($result)->toHaveCount(3)
            ->and(array_keys($result))->toBe([0, 1, 2]);
    });

    it('includes classificationCode in transformed keywords', function (): void {
        Subject::factory()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Nanobiotechnology',
            'subject_scheme' => 'ANZSRC Fields of Research',
            'scheme_uri' => 'https://www.abs.gov.au/statistics/classifications/australian-and-new-zealand-standard-research-classification-anzsrc',
            'value_uri' => null,
            'classification_code' => '310607',
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGcmdKeywords($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['classificationCode'])->toBe('310607')
            ->and($result[0]['id'])->toBe('310607')
            ->and($result[0]['text'])->toBe('Nanobiotechnology');
    });

    it('returns no classificationCode key when not set', function (): void {
        Subject::factory()->gcmd()->create([
            'resource_id' => $this->resource->id,
            'value' => 'Earthquakes',
            'classification_code' => null,
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGcmdKeywords($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0])->not->toHaveKey('classificationCode');
    });
});

describe('transformGemetKeywords', function (): void {
    it('returns sequential array keys when non-GEMET keywords precede GEMET keywords', function (): void {
        // Create GCMD keywords first (these will be filtered OUT, creating gaps in keys)
        Subject::factory()->gcmd()->count(4)->create([
            'resource_id' => $this->resource->id,
        ]);
        // Create GEMET keywords after (their collection keys will be 4, 5)
        Subject::factory()->count(2)->create([
            'resource_id' => $this->resource->id,
            'value' => 'Environmental monitoring',
            'subject_scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
            'scheme_uri' => 'https://www.eionet.europa.eu/gemet/',
            'value_uri' => 'https://www.eionet.europa.eu/gemet/concept/' . fake()->numberBetween(1000, 9999),
        ]);
        $this->resource->load('subjects');

        $result = $this->transformer->transformGemetKeywords($this->resource);

        expect($result)->toHaveCount(2)
            ->and(array_keys($result))->toBe([0, 1])
            ->and($result[0]['text'])->toBe('Environmental monitoring')
            ->and($result[0]['scheme'])->toBe('GEMET - GEneral Multilingual Environmental Thesaurus');
    });
});

// =========================================================================
// transformCoverages
// =========================================================================

describe('transformCoverages', function (): void {
    it('transforms geoLocations with point coordinates', function (): void {
        GeoLocation::factory()->withPoint(13.0, 52.0)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam, Germany',
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['description'])->toBe('Potsdam, Germany')
            ->and($result[0]['timezone'])->toBe('UTC');
    });

    it('returns empty strings for null coordinates', function (): void {
        GeoLocation::factory()->create([
            'resource_id' => $this->resource->id,
            'place' => 'Unknown',
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        expect($result[0]['latMin'])->toBe('')
            ->and($result[0]['latMax'])->toBe('')
            ->and($result[0]['lonMin'])->toBe('')
            ->and($result[0]['lonMax'])->toBe('');
    });

    it('transforms bounding box coordinates as strings', function (): void {
        GeoLocation::factory()->withBox(10.0, 15.0, 50.0, 55.0)->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        expect($result[0]['lonMin'])->toContain('10')
            ->and($result[0]['lonMax'])->toContain('15')
            ->and($result[0]['latMin'])->toContain('50')
            ->and($result[0]['latMax'])->toContain('55');
    });

    it('transforms polygon geo locations with polygon points', function (): void {
        GeoLocation::factory()->withPolygon()->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['type'])->toBe('polygon')
            ->and($result[0]['polygonPoints'])->toBeArray()
            ->and(count($result[0]['polygonPoints']))->toBeGreaterThanOrEqual(3);
    });

    it('transforms line geo locations with line points', function (): void {
        GeoLocation::factory()->withLine()->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['type'])->toBe('line')
            ->and($result[0]['polygonPoints'])->toBeArray()
            ->and(count($result[0]['polygonPoints']))->toBeGreaterThanOrEqual(2);
    });

    it('returns correct type for each geo location kind', function (): void {
        GeoLocation::factory()->withPoint(13.0, 52.0)->create([
            'resource_id' => $this->resource->id,
        ]);
        GeoLocation::factory()->withBox(10.0, 15.0, 50.0, 55.0)->create([
            'resource_id' => $this->resource->id,
        ]);
        GeoLocation::factory()->withLine()->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load('geoLocations');

        $result = $this->transformer->transformCoverages($this->resource);

        $types = array_column($result, 'type');
        expect($types)->toContain('point')
            ->and($types)->toContain('box')
            ->and($types)->toContain('line');
    });
});

// =========================================================================
// transformRelatedIdentifiers
// =========================================================================

describe('transformRelatedIdentifiers', function (): void {
    it('transforms related identifiers sorted by position', function (): void {
        $identifierType = IdentifierType::firstOrCreate(
            ['slug' => 'doi'],
            ['name' => 'DOI', 'is_active' => true]
        );
        $relationType = RelationType::firstOrCreate(
            ['slug' => 'cites'],
            ['name' => 'Cites', 'is_active' => true]
        );

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.5880/test.2024.002',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 2,
        ]);
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.5880/test.2024.001',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 1,
        ]);

        $this->resource->load('relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType');

        $result = $this->transformer->transformRelatedIdentifiers($this->resource);

        expect($result)->toHaveCount(2)
            ->and($result[0]['identifier'])->toBe('10.5880/test.2024.001')
            ->and($result[1]['identifier'])->toBe('10.5880/test.2024.002')
            ->and($result[0]['identifier_type'])->toBe('DOI')
            ->and($result[0]['relation_type'])->toBe('Cites');
    });
});

// =========================================================================
// transformFundingReferences
// =========================================================================

describe('transformFundingReferences', function (): void {
    it('transforms funding references sorted by position', function (): void {
        \App\Models\FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'DFG',
            'funder_identifier' => 'https://doi.org/10.13039/501100001659',
            'award_number' => 'ABC-123',
            'award_uri' => 'https://dfg.de/award/123',
            'award_title' => 'Research Grant',
            'position' => 2,
        ]);
        \App\Models\FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'EU',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'award_number' => 'XYZ-789',
            'award_uri' => null,
            'award_title' => null,
            'position' => 1,
        ]);
        $this->resource->load('fundingReferences');

        $result = $this->transformer->transformFundingReferences($this->resource);

        expect($result)->toHaveCount(2)
            ->and($result[0]['funderName'])->toBe('DFG')
            ->and($result[1]['funderName'])->toBe('EU')
            ->and($result[0]['awardTitle'])->toBe('Research Grant')
            ->and($result[1]['awardUri'])->toBe('')
            ->and($result[1]['awardTitle'])->toBe('');
    });

    it('returns funderIdentifierType name from relationship', function (): void {
        $rorType = \App\Models\FunderIdentifierType::firstOrCreate(
            ['slug' => 'ROR'],
            ['name' => 'ROR', 'is_active' => true]
        );
        $crossrefType = \App\Models\FunderIdentifierType::firstOrCreate(
            ['slug' => 'Crossref Funder ID'],
            ['name' => 'Crossref Funder ID', 'is_active' => true]
        );

        \App\Models\FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'DFG',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type_id' => $rorType->id,
            'position' => 1,
        ]);
        \App\Models\FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'EU',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'funder_identifier_type_id' => $crossrefType->id,
            'position' => 2,
        ]);
        $this->resource->load('fundingReferences.funderIdentifierType');

        $result = $this->transformer->transformFundingReferences($this->resource);

        expect($result[0]['funderIdentifierType'])->toBe('ROR')
            ->and($result[1]['funderIdentifierType'])->toBe('Crossref Funder ID');
    });

    it('returns empty string for funderIdentifierType when none is set', function (): void {
        \App\Models\FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Generic Funder',
            'funder_identifier' => null,
            'funder_identifier_type_id' => null,
            'position' => 1,
        ]);
        $this->resource->load('fundingReferences.funderIdentifierType');

        $result = $this->transformer->transformFundingReferences($this->resource);

        expect($result[0]['funderIdentifierType'])->toBe('');
    });
});

// =========================================================================
// transformMslLaboratories
// =========================================================================

describe('transformMslLaboratories', function (): void {
    it('extracts MSL laboratories from creators', function (): void {
        $lab = Institution::factory()->create([
            'name' => 'Rock Mechanics Lab',
            'name_identifier' => 'lab-42',
            'name_identifier_scheme' => 'labid',
        ]);

        $creator = ResourceCreator::factory()->forInstitution($lab)->create([
            'resource_id' => $this->resource->id,
            'position' => 1,
        ]);

        Affiliation::create([
            'affiliatable_type' => ResourceCreator::class,
            'affiliatable_id' => $creator->id,
            'name' => 'Utrecht University',
            'identifier' => 'https://ror.org/04pp8hn57',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org',
        ]);

        $this->resource->load(['creators.creatorable', 'creators.affiliations']);

        $result = $this->transformer->transformMslLaboratories($this->resource);

        expect($result)->toHaveCount(1)
            ->and($result[0]['identifier'])->toBe('lab-42')
            ->and($result[0]['name'])->toBe('Rock Mechanics Lab')
            ->and($result[0]['affiliation_name'])->toBe('Utrecht University')
            ->and($result[0]['affiliation_ror'])->toBe('https://ror.org/04pp8hn57');
    });

    it('returns empty array when no MSL labs exist', function (): void {
        $this->resource->load(['creators.creatorable', 'creators.affiliations']);

        $result = $this->transformer->transformMslLaboratories($this->resource);

        expect($result)->toBeEmpty();
    });

    it('excludes non-labid institutions', function (): void {
        $institution = Institution::factory()->withRor()->create(['name' => 'Regular University']);
        ResourceCreator::factory()->forInstitution($institution)->create([
            'resource_id' => $this->resource->id,
        ]);
        $this->resource->load(['creators.creatorable', 'creators.affiliations']);

        $result = $this->transformer->transformMslLaboratories($this->resource);

        expect($result)->toBeEmpty();
    });
});

// =========================================================================
// getCommonProps
// =========================================================================

describe('getCommonProps', function (): void {
    it('returns expected keys with defaults', function (): void {
        $result = $this->transformer->getCommonProps();

        expect($result)->toHaveKeys(['maxTitles', 'maxLicenses', 'googleMapsApiKey'])
            ->and($result['maxTitles'])->toBe(99)
            ->and($result['maxLicenses'])->toBe(99);
    });

    it('reads maxTitles from settings when configured', function (): void {
        Setting::create(['key' => 'max_titles', 'value' => '5']);

        $result = $this->transformer->getCommonProps();

        expect($result['maxTitles'])->toBe(5);
    });

    it('reads maxLicenses from settings when configured', function (): void {
        Setting::create(['key' => 'max_licenses', 'value' => '3']);

        $result = $this->transformer->getCommonProps();

        expect($result['maxLicenses'])->toBe(3);
    });
});
