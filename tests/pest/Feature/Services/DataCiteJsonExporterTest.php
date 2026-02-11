<?php

use App\Models\ContributorType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\DataCiteJsonExporter;

beforeEach(function () {
    $this->exporter = new DataCiteJsonExporter;
});

describe('DataCiteJsonExporter - JSON Structure', function () {
    test('exports valid JSON with proper structure', function () {
        $resource = Resource::factory()->create();

        $result = $this->exporter->export($resource);

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('data')
            ->and($result['data'])->toHaveKey('type', 'dois')
            ->and($result['data'])->toHaveKey('attributes');
    });

    test('exports required attributes', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-1234',
            'publication_year' => 2024,
        ]);

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->toHaveKey('doi', '10.82433/TEST-1234')
            ->and($attributes)->toHaveKey('publicationYear', 2024)
            ->and($attributes)->toHaveKey('titles')
            ->and($attributes)->toHaveKey('creators')
            ->and($attributes)->toHaveKey('publisher')
            ->and($attributes)->toHaveKey('types')
            ->and($attributes)->not->toHaveKey('schemaVersion');
    });
});

describe('DataCiteJsonExporter - Titles', function () {
    test('exports main title correctly', function () {
        $resource = Resource::factory()->create();
        $mainTitleType = TitleType::firstOrCreate(['slug' => 'main-title'], ['name' => 'Main Title']);
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Main Title',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $titles = $result['data']['attributes']['titles'];

        expect($titles)->toBeArray()
            ->and($titles[0])->toHaveKey('title', 'Test Main Title');
    });

    test('exports subtitle with correct titleType', function () {
        $resource = Resource::factory()->create();
        $subtitleType = TitleType::firstOrCreate(['slug' => 'subtitle'], ['name' => 'Subtitle']);
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'A Subtitle',
            'title_type_id' => $subtitleType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $titles = $result['data']['attributes']['titles'];

        expect($titles[0])->toHaveKey('title', 'A Subtitle')
            ->and($titles[0])->toHaveKey('titleType', 'Subtitle');
    });

    test('exports alternative title with correct titleType', function () {
        $resource = Resource::factory()->create();
        $alternativeType = TitleType::firstOrCreate(['slug' => 'alternative-title'], ['name' => 'Alternative Title']);
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Alternative Title Text',
            'title_type_id' => $alternativeType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $titles = $result['data']['attributes']['titles'];

        expect($titles[0])->toHaveKey('titleType', 'AlternativeTitle');
    });
});

describe('DataCiteJsonExporter - Creators', function () {
    test('exports person creator with name and ORCID', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
            'name_identifier' => 'https://orcid.org/0000-0002-1234-5678',
            'name_identifier_scheme' => 'ORCID',
            'scheme_uri' => 'https://orcid.org/',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        expect($creators)->toBeArray()
            ->and($creators[0])->toHaveKey('name', 'Doe, Jane')
            ->and($creators[0])->toHaveKey('nameType', 'Personal')
            ->and($creators[0])->toHaveKey('givenName', 'Jane')
            ->and($creators[0])->toHaveKey('familyName', 'Doe')
            ->and($creators[0])->toHaveKey('nameIdentifiers');

        $nameIdentifiers = $creators[0]['nameIdentifiers'];
        expect($nameIdentifiers[0])->toHaveKey('nameIdentifier', 'https://orcid.org/0000-0002-1234-5678')
            ->and($nameIdentifiers[0])->toHaveKey('nameIdentifierScheme', 'ORCID');
    });

    test('exports institutional creator', function () {
        $resource = Resource::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'GFZ Potsdam',
            'name_identifier' => 'https://ror.org/04z8jg394',
            'name_identifier_scheme' => 'ROR',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        expect($creators[0])->toHaveKey('name', 'GFZ Potsdam')
            ->and($creators[0])->toHaveKey('nameType', 'Organizational');
    });

    test('returns fallback creator when no creators exist', function () {
        $resource = Resource::factory()->create();

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        expect($creators)->toHaveCount(1)
            ->and($creators[0])->toHaveKey('name', 'Unknown')
            ->and($creators[0])->toHaveKey('nameType', 'Personal');
    });
});

describe('DataCiteJsonExporter - Contributors', function () {
    test('exports person contributor with contributorType', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'John',
            'family_name' => 'Smith',
        ]);
        // Use the correct slug format matching the seeder (PascalCase for DataCite compliance)
        $contactPersonType = ContributorType::firstOrCreate(
            ['slug' => 'ContactPerson'],
            ['name' => 'Contact Person']
        );

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contactPersonType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $contributors = $result['data']['attributes']['contributors'];

        // Exporter now uses slug for DataCite compliance (PascalCase)
        expect($contributors)->toBeArray()
            ->and($contributors[0])->toHaveKey('name', 'Smith, John')
            ->and($contributors[0])->toHaveKey('contributorType', 'ContactPerson');
    });

    test('omits contributors key when no contributors exist', function () {
        $resource = Resource::factory()->create();

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->not->toHaveKey('contributors');
    });
});

describe('DataCiteJsonExporter - Descriptions', function () {
    test('exports abstract description', function () {
        $resource = Resource::factory()->create();
        $abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'abstract'],
            ['name' => 'Abstract']
        );
        Description::create([
            'resource_id' => $resource->id,
            'value' => 'This is the abstract text.',
            'description_type_id' => $abstractType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $descriptions = $result['data']['attributes']['descriptions'];

        expect($descriptions)->toBeArray()
            ->and($descriptions[0])->toHaveKey('description', 'This is the abstract text.')
            ->and($descriptions[0]['descriptionType'])->toBeIn(['Abstract', 'abstract']);
    });

    test('exports methods description', function () {
        $resource = Resource::factory()->create();
        $methodsType = DescriptionType::firstOrCreate(
            ['slug' => 'methods'],
            ['name' => 'Methods']
        );
        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Methodology description.',
            'description_type_id' => $methodsType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $descriptions = $result['data']['attributes']['descriptions'];

        expect($descriptions[0]['descriptionType'])->toBeIn(['Methods', 'methods']);
    });
});

describe('DataCiteJsonExporter - Resource Types', function () {
    test('exports resource type correctly', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset']
        );
        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
        ]);

        $result = $this->exporter->export($resource);
        $types = $result['data']['attributes']['types'];

        expect($types)->toHaveKey('resourceTypeGeneral', 'Dataset')
            ->and($types)->toHaveKey('resourceType', 'Dataset');
    });
});

describe('DataCiteJsonExporter - Rights/Licenses', function () {
    test('exports license information', function () {
        $resource = Resource::factory()->create();
        $license = Right::firstOrCreate(
            ['identifier' => 'CC-BY-4.0'],
            [
                'name' => 'Creative Commons Attribution 4.0 International',
                'uri' => 'https://creativecommons.org/licenses/by/4.0/',
            ]
        );
        $resource->rights()->attach($license->id);

        $result = $this->exporter->export($resource);
        $rightsList = $result['data']['attributes']['rightsList'];

        expect($rightsList)->toBeArray()
            ->and($rightsList[0])->toHaveKey('rights', 'Creative Commons Attribution 4.0 International')
            ->and($rightsList[0])->toHaveKey('rightsIdentifier', 'CC-BY-4.0');
    });
});

describe('DataCiteJsonExporter - Subjects/Keywords', function () {
    test('exports GCMD subject with scheme', function () {
        $resource = Resource::factory()->create();
        $subject = Subject::create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS',
            'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/KeywordViewer/',
        ]);

        $result = $this->exporter->export($resource);
        $subjects = $result['data']['attributes']['subjects'];

        expect($subjects)->toBeArray()
            ->and($subjects[0])->toHaveKey('subject', 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS')
            ->and($subjects[0])->toHaveKey('subjectScheme', 'NASA/GCMD Earth Science Keywords');
    });

    test('exports free keyword without scheme', function () {
        $resource = Resource::factory()->create();
        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'seismology',
            'subject_scheme' => null,
            'scheme_uri' => null,
        ]);

        $result = $this->exporter->export($resource);
        $subjects = $result['data']['attributes']['subjects'];

        expect($subjects[0])->toHaveKey('subject', 'seismology')
            ->and($subjects[0])->not->toHaveKey('subjectScheme');
    });
});

use App\Models\Publisher;

describe('DataCiteJsonExporter - Publisher', function () {
    test('returns default publisher with full DataCite 4.6 fields when none set', function () {
        // Create default publisher with all fields
        Publisher::firstOrCreate(
            ['name' => 'GFZ Data Services'],
            [
                'name' => 'GFZ Data Services',
                'identifier' => 'https://doi.org/10.17616/R3VQ0S',
                'identifier_scheme' => 're3data',
                'scheme_uri' => 'https://re3data.org/',
                'language' => 'en',
                'is_default' => true,
            ]
        );

        $resource = Resource::factory()->create(['publisher_id' => null]);

        $result = $this->exporter->export($resource);
        $publisher = $result['data']['attributes']['publisher'];

        expect($publisher)
            ->toHaveKey('name', 'GFZ Data Services')
            ->toHaveKey('publisherIdentifier', 'https://doi.org/10.17616/R3VQ0S')
            ->toHaveKey('publisherIdentifierScheme', 're3data')
            ->toHaveKey('schemeUri', 'https://re3data.org/')
            ->toHaveKey('lang', 'en');
    });

    test('exports publisher with all DataCite 4.6 fields when resource has publisher', function () {
        $resource = Resource::factory()->create();

        $result = $this->exporter->export($resource);
        $publisher = $result['data']['attributes']['publisher'];

        expect($publisher)
            ->toHaveKey('name', 'GFZ Data Services')
            ->toHaveKey('publisherIdentifier', 'https://doi.org/10.17616/R3VQ0S')
            ->toHaveKey('publisherIdentifierScheme', 're3data')
            ->toHaveKey('schemeUri', 'https://re3data.org/')
            ->toHaveKey('lang', 'en');
    });

    test('exports custom publisher with identifier', function () {
        $publisher = Publisher::factory()->create([
            'name' => 'Custom Publisher',
            'identifier' => 'https://ror.org/01234567',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
            'language' => 'de',
        ]);
        $resource = Resource::factory()->create([
            'publisher_id' => $publisher->id,
        ]);

        $result = $this->exporter->export($resource);
        $publisherData = $result['data']['attributes']['publisher'];

        expect($publisherData)
            ->toHaveKey('name', 'Custom Publisher')
            ->toHaveKey('publisherIdentifier', 'https://ror.org/01234567')
            ->toHaveKey('publisherIdentifierScheme', 'ROR')
            ->toHaveKey('schemeUri', 'https://ror.org/')
            ->toHaveKey('lang', 'de');
    });

    test('preserves imported publisher data from DataCite', function () {
        // Simulate an imported publisher with different data
        $importedPublisher = Publisher::factory()->create([
            'name' => 'External Repository',
            'identifier' => 'https://ror.org/99999999',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
            'language' => 'fr',
            'is_default' => false,
        ]);
        $resource = Resource::factory()->create([
            'publisher_id' => $importedPublisher->id,
        ]);

        $result = $this->exporter->export($resource);
        $publisherData = $result['data']['attributes']['publisher'];

        // Should use the resource's assigned publisher, not the default
        expect($publisherData)
            ->toHaveKey('name', 'External Repository')
            ->toHaveKey('publisherIdentifier', 'https://ror.org/99999999')
            ->toHaveKey('lang', 'fr');
    });

    test('hardcoded fallback includes all DataCite 4.6 publisher fields', function () {
        // Create resource first (factory seeds a default publisher via firstOrCreate),
        // then delete ALL publishers and clear the resource's publisher_id so the
        // exporter's hardcoded fallback branch is actually exercised.
        $resource = Resource::factory()->create(['publisher_id' => null]);
        Publisher::query()->delete();

        $result = $this->exporter->export($resource);
        $publisherData = $result['data']['attributes']['publisher'];

        expect($publisherData)
            ->toHaveKey('name', 'GFZ Data Services')
            ->toHaveKey('publisherIdentifier', 'https://doi.org/10.17616/R3VQ0S')
            ->toHaveKey('publisherIdentifierScheme', 're3data')
            ->toHaveKey('schemeUri', 'https://re3data.org/')
            ->toHaveKey('lang', 'en');
    });

    test('exports full publisher when resource has GFZ publisher assigned', function () {
        $publisher = Publisher::factory()->gfz()->create();
        $resource = Resource::factory()->create(['publisher_id' => $publisher->id]);

        $result = $this->exporter->export($resource);
        $publisherData = $result['data']['attributes']['publisher'];

        expect($publisherData)
            ->toHaveKey('name', 'GFZ Data Services')
            ->toHaveKey('publisherIdentifier', 'https://doi.org/10.17616/R3VQ0S')
            ->toHaveKey('publisherIdentifierScheme', 're3data')
            ->toHaveKey('schemeUri', 'https://re3data.org/')
            ->toHaveKey('lang', 'en');
    });
});

describe('DataCiteJsonExporter - Optional Fields', function () {
    test('exports language when present', function () {
        $language = \App\Models\Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'active' => true]
        );
        $resource = Resource::factory()->create([
            'language_id' => $language->id,
        ]);

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->toHaveKey('language', 'en');
    });

    test('exports version when present', function () {
        $resource = Resource::factory()->create(['version' => '2.1.0']);

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->toHaveKey('version', '2.1.0');
    });

    test('omits language when not set', function () {
        $resource = Resource::factory()->create(['language_id' => null]);

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->not->toHaveKey('language');
    });

    test('omits version when not set', function () {
        $resource = Resource::factory()->create(['version' => null]);

        $result = $this->exporter->export($resource);
        $attributes = $result['data']['attributes'];

        expect($attributes)->not->toHaveKey('version');
    });
});

describe('DataCiteJsonExporter - IGSN Contributors as Creators', function () {
    beforeEach(function () {
        // Ensure Physical Object resource type exists for IGSN tests
        $this->physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'datacite_type' => 'PhysicalObject']
        );
    });

    test('includes person contributors as creators for IGSN resources', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Add a creator
        $creator = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator->id,
            'position' => 1,
        ]);

        // Add person contributors
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        $contributor1 = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'Jane',
        ]);
        $contributor2 = Person::factory()->create([
            'family_name' => 'Brown',
            'given_name' => 'Bob',
        ]);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor1->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor2->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 2,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should have 3 creators: 1 original + 2 from contributors
        expect($creators)->toHaveCount(3);
        expect($creators[0]['name'])->toBe('Smith, John');
        expect($creators[1]['name'])->toBe('Doe, Jane');
        expect($creators[2]['name'])->toBe('Brown, Bob');
    });

    test('does not include contributors as creators for non-IGSN resources', function () {
        // Create regular resource without IGSN metadata
        $resource = Resource::factory()->create();

        // Add a creator
        $creator = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator->id,
            'position' => 1,
        ]);

        // Add person contributor
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        $contributor = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'Jane',
        ]);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (the original)
        expect($creators)->toHaveCount(1);
        expect($creators[0]['name'])->toBe('Smith, John');
    });

    test('avoids duplicate creators when person is both creator and contributor in IGSN', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Create person with ORCID
        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => '0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // Add as both creator and contributor
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (duplicate avoided)
        expect($creators)->toHaveCount(1);
        expect($creators[0]['name'])->toBe('Smith, John');
    });

    test('avoids duplicates by name when person has no ORCID', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Create person without ORCID - use same person as creator and contributor
        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => null,
            'name_identifier_scheme' => null,
        ]);

        // Add as both creator and contributor
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (duplicate avoided by name)
        expect($creators)->toHaveCount(1);
    });

    test('avoids duplicates when creator has ORCID but contributor does not', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Create person with ORCID as creator
        $personWithOrcid = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => '0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // Create same person without ORCID as contributor (e.g., data entry error)
        $personWithoutOrcid = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => null,
            'name_identifier_scheme' => null,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $personWithOrcid->id,
            'position' => 1,
        ]);

        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $personWithoutOrcid->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (duplicate detected by name even though ORCID differs)
        expect($creators)->toHaveCount(1);
        expect($creators[0]['name'])->toBe('Smith, John');
    });

    test('avoids duplicates when ORCID is stored as URL vs bare ID', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Create person with ORCID as full URL (as stored by PersonFactory::withOrcid())
        $personWithUrlOrcid = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // Create same person with ORCID as bare ID
        $personWithBareOrcid = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
            'name_identifier' => '0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $personWithUrlOrcid->id,
            'position' => 1,
        ]);

        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $personWithBareOrcid->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (ORCID normalized, duplicate detected)
        expect($creators)->toHaveCount(1);
        expect($creators[0]['name'])->toBe('Smith, John');
    });

    test('excludes institution contributors from creators array in IGSN export', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Add a person creator
        $creator = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator->id,
            'position' => 1,
        ]);

        // Add institution contributor
        $institution = Institution::factory()->create(['name' => 'Test Lab']);
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'HostingInstitution'],
            ['name' => 'Hosting Institution']
        );
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Institution::class,
            'contributorable_id' => $institution->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Should only have 1 creator (institution excluded)
        expect($creators)->toHaveCount(1);
        expect($creators[0]['name'])->toBe('Smith, John');
    });

    test('preserves contributors in contributors element for IGSN resources', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Add a creator
        $creator = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator->id,
            'position' => 1,
        ]);

        // Add person contributors
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        $contributor1 = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'Jane',
        ]);
        $contributor2 = Person::factory()->create([
            'family_name' => 'Brown',
            'given_name' => 'Bob',
        ]);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor1->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor2->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 2,
        ]);

        $result = $this->exporter->export($resource);
        $contributors = $result['data']['attributes']['contributors'];

        // Contributors should still appear in the contributors element
        expect($contributors)->toHaveCount(2);
        expect($contributors[0]['name'])->toBe('Doe, Jane');
        expect($contributors[0]['contributorType'])->toBe('DataCollector');
        expect($contributors[1]['name'])->toBe('Brown, Bob');
    });

    test('maintains creator order: original creators first, then contributors', function () {
        // Create IGSN resource with Physical Object type
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->physicalObjectType->id,
        ]);
        \App\Models\IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Add creators
        $creator1 = Person::factory()->create(['family_name' => 'First', 'given_name' => 'Creator']);
        $creator2 = Person::factory()->create(['family_name' => 'Second', 'given_name' => 'Creator']);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator1->id,
            'position' => 1,
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $creator2->id,
            'position' => 2,
        ]);

        // Add contributor
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        $contributor = Person::factory()->create(['family_name' => 'Third', 'given_name' => 'Contributor']);
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contributor->id,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creators = $result['data']['attributes']['creators'];

        // Verify order: creators first, then contributors
        expect($creators)->toHaveCount(3);
        expect($creators[0]['name'])->toBe('First, Creator');
        expect($creators[1]['name'])->toBe('Second, Creator');
        expect($creators[2]['name'])->toBe('Third, Contributor');
    });
});

describe('DataCiteJsonExporter - Affiliations', function () {
    test('exports affiliation with name only (no ROR)', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $creator->affiliations()->create([
            'name' => 'University of Potsdam',
            'identifier' => null,
            'identifier_scheme' => null,
            'scheme_uri' => null,
        ]);

        $result = $this->exporter->export($resource);
        $affiliations = $result['data']['attributes']['creators'][0]['affiliation'];

        expect($affiliations)->toHaveCount(1)
            ->and($affiliations[0])->toHaveKey('name', 'University of Potsdam')
            ->and($affiliations[0])->not->toHaveKey('affiliationIdentifier')
            ->and($affiliations[0])->not->toHaveKey('affiliationIdentifierScheme')
            ->and($affiliations[0])->not->toHaveKey('schemeURI');
    });

    test('exports affiliation with name and ROR identifier', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $creator->affiliations()->create([
            'name' => 'University of Lausanne',
            'identifier' => 'https://ror.org/019whta54',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);

        $result = $this->exporter->export($resource);
        $affiliations = $result['data']['attributes']['creators'][0]['affiliation'];

        expect($affiliations)->toHaveCount(1)
            ->and($affiliations[0])->toHaveKey('name', 'University of Lausanne')
            ->and($affiliations[0])->toHaveKey('affiliationIdentifier', 'https://ror.org/019whta54')
            ->and($affiliations[0])->toHaveKey('affiliationIdentifierScheme', 'ROR')
            ->and($affiliations[0])->toHaveKey('schemeURI', 'https://ror.org/');
    });

    test('exports schemeURI fallback for ROR affiliations without scheme_uri in DB', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        // Simulate older record without scheme_uri
        $creator->affiliations()->create([
            'name' => 'GFZ Potsdam',
            'identifier' => 'https://ror.org/04z8jg394',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => null,
        ]);

        $result = $this->exporter->export($resource);
        $affiliations = $result['data']['attributes']['creators'][0]['affiliation'];

        expect($affiliations)->toHaveCount(1)
            ->and($affiliations[0])->toHaveKey('affiliationIdentifier', 'https://ror.org/04z8jg394')
            ->and($affiliations[0])->toHaveKey('affiliationIdentifierScheme', 'ROR')
            ->and($affiliations[0])->toHaveKey('schemeURI', 'https://ror.org');
    });

    test('does not export empty affiliations array', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);
        $creator = $result['data']['attributes']['creators'][0];

        expect($creator)->not->toHaveKey('affiliation');
    });

    test('exports multiple affiliations for one creator', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $creator->affiliations()->create([
            'name' => 'GFZ Potsdam',
            'identifier' => 'https://ror.org/04z8jg394',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);
        $creator->affiliations()->create([
            'name' => 'University of Potsdam',
            'identifier' => null,
            'identifier_scheme' => null,
            'scheme_uri' => null,
        ]);

        $result = $this->exporter->export($resource);
        $affiliations = $result['data']['attributes']['creators'][0]['affiliation'];

        expect($affiliations)->toHaveCount(2)
            ->and($affiliations[0])->toHaveKey('name', 'GFZ Potsdam')
            ->and($affiliations[0])->toHaveKey('affiliationIdentifier', 'https://ror.org/04z8jg394')
            ->and($affiliations[1])->toHaveKey('name', 'University of Potsdam')
            ->and($affiliations[1])->not->toHaveKey('affiliationIdentifier');
    });

    test('exports contributor affiliations correctly', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create(['given_name' => 'John', 'family_name' => 'Smith']);
        $contributorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector']
        );
        $contributor = ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ]);

        $contributor->affiliations()->create([
            'name' => 'GFZ Potsdam',
            'identifier' => 'https://ror.org/04z8jg394',
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);

        $result = $this->exporter->export($resource);
        $contributors = $result['data']['attributes']['contributors'];

        expect($contributors[0])->toHaveKey('affiliation')
            ->and($contributors[0]['affiliation'][0])->toHaveKey('affiliationIdentifier', 'https://ror.org/04z8jg394')
            ->and($contributors[0]['affiliation'][0])->toHaveKey('name', 'GFZ Potsdam');
    });
});
