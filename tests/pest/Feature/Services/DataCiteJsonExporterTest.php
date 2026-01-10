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
            ->and($attributes)->toHaveKey('types');
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
        $contactPersonType = ContributorType::firstOrCreate(
            ['slug' => 'contact-person'],
            ['name' => 'ContactPerson']
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
    test('returns default publisher when none set', function () {
        $resource = Resource::factory()->create(['publisher_id' => null]);

        $result = $this->exporter->export($resource);
        $publisher = $result['data']['attributes']['publisher'];

        expect($publisher)->toHaveKey('name', 'GFZ Data Services');
    });

    test('exports custom publisher with identifier', function () {
        $publisher = Publisher::factory()->create([
            'name' => 'Custom Publisher',
            'identifier' => 'https://ror.org/01234567',
            'identifier_scheme' => 'ROR',
        ]);
        $resource = Resource::factory()->create([
            'publisher_id' => $publisher->id,
        ]);

        $result = $this->exporter->export($resource);
        $publisherData = $result['data']['attributes']['publisher'];

        expect($publisherData)->toHaveKey('name', 'Custom Publisher');
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
