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
use App\Models\Title;
use App\Models\TitleType;
use App\Services\DataCiteJsonExporter;

beforeEach(function () {
    $this->exporter = new DataCiteJsonExporter;
});

describe('DataCiteJsonExporter - Required Fields', function () {
    test('exports required identifier (DOI)', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/B09Z-4K37',
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.doi')
            ->and($result['data']['attributes']['doi'])->toBe('10.82433/B09Z-4K37');
    });

    test('handles missing DOI gracefully', function () {
        $resource = Resource::factory()->create(['doi' => null]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.doi')
            ->and($result['data']['attributes']['doi'])->toBeNull();
    });

    test('exports required creators', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Holger',
            'family_name' => 'Ehrmann',
            'name_identifier' => 'https://orcid.org/0009-0000-1235-6950',
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

        expect($result)->toHaveKey('data.attributes.creators')
            ->and($result['data']['attributes']['creators'])->toHaveCount(1)
            ->and($result['data']['attributes']['creators'][0])
            ->toMatchArray([
                'nameType' => 'Personal',
                'name' => 'Ehrmann, Holger',
                'givenName' => 'Holger',
                'familyName' => 'Ehrmann',
            ]);
    });

    test('exports required titles', function () {
        $resource = Resource::factory()->create();

        // Get or create MainTitle type
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Dataset Software',
            'title_type_id' => $titleType->id,
            'language' => 'en',
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.titles')
            ->and($result['data']['attributes']['titles'])->toHaveCount(1)
            ->and($result['data']['attributes']['titles'][0])
            ->toMatchArray([
                'title' => 'Test Dataset Software',
                'lang' => 'en',
            ]);
    });

    test('exports required publisher', function () {
        $resource = Resource::factory()->create();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.publisher')
            ->and($result['data']['attributes']['publisher'])->toHaveKey('name');
    });

    test('exports required publicationYear', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.publicationYear')
            ->and($result['data']['attributes']['publicationYear'])->toBe(2024);
    });

    test('exports required resourceType', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $resourceType->id]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.types')
            ->and($result['data']['attributes']['types'])
            ->toMatchArray([
                'resourceTypeGeneral' => 'Dataset',
                'resourceType' => 'Dataset',
            ]);
    });
});

describe('DataCiteJsonExporter - Creators & Contributors', function () {
    test('distinguishes between creators and contributors', function () {
        $resource = Resource::factory()->create();

        $person = Person::factory()->create([
            'given_name' => 'Holger',
            'family_name' => 'Ehrmann',
            'name_identifier' => 'https://orcid.org/0009-0000-1235-6950',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // Add as creator
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        // Add as contributor (Contact Person)
        $contactPersonType = ContributorType::firstOrCreate(
            ['slug' => 'ContactPerson'],
            ['name' => 'Contact Person', 'slug' => 'ContactPerson', 'is_active' => true]
        );

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contactPersonType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'])->toHaveCount(1)
            ->and($result['data']['attributes']['creators'][0]['name'])->toBe('Ehrmann, Holger')
            ->and($result['data']['attributes']['contributors'])->toHaveCount(1)
            ->and($result['data']['attributes']['contributors'][0]['name'])->toBe('Ehrmann, Holger')
            ->and($result['data']['attributes']['contributors'][0]['contributorType'])->toBe('ContactPerson');
    });

    test('exports organizational creator with ROR', function () {
        $resource = Resource::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'Library and Information Services',
            'name_identifier' => 'https://ror.org/04z8jg394',
            'name_identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'][0])
            ->toMatchArray([
                'name' => 'Library and Information Services',
                'nameType' => 'Organizational',
            ])
            ->and($result['data']['attributes']['creators'][0]['nameIdentifiers'][0])
            ->toMatchArray([
                'nameIdentifier' => 'https://ror.org/04z8jg394',
                'nameIdentifierScheme' => 'ROR',
                'schemeUri' => 'https://ror.org/',
            ]);
    });

    test('exports contributor with correct contributorType', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Ali',
            'family_name' => 'Mohammed',
        ]);

        $dataCollectorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector', 'slug' => 'DataCollector', 'is_active' => true]
        );

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $dataCollectorType->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['contributors'][0])
            ->toHaveKey('contributorType')
            ->and($result['data']['attributes']['contributors'][0]['contributorType'])
            ->toBe('DataCollector');
    });

    test('handles creator with only family name', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => null,
            'family_name' => 'Einstein',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'][0])
            ->toMatchArray([
                'name' => 'Einstein',
                'familyName' => 'Einstein',
            ])
            ->and($result['data']['attributes']['creators'][0])->not->toHaveKey('givenName');
    });

    test('handles creator with only given name', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Madonna',
            'family_name' => null,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'][0])
            ->toMatchArray([
                'name' => 'Madonna',
                'givenName' => 'Madonna',
            ])
            ->and($result['data']['attributes']['creators'][0])->not->toHaveKey('familyName');
    });
});

describe('DataCiteJsonExporter - Titles', function () {
    test('exports multiple titles with different types', function () {
        $resource = Resource::factory()->create();

        $mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        $alternativeTitleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Main Title',
            'title_type_id' => $mainTitleType->id,
            'language' => 'en',
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Alternative Title',
            'title_type_id' => $alternativeTitleType->id,
            'language' => 'en',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['titles'])->toHaveCount(2)
            ->and($result['data']['attributes']['titles'][0])->toMatchArray([
                'title' => 'Main Title',
                'lang' => 'en',
            ])
            ->and($result['data']['attributes']['titles'][1])->toMatchArray([
                'title' => 'Alternative Title',
                'titleType' => 'AlternativeTitle',
                'lang' => 'en',
            ]);
    });

    test('handles title without language', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Title without language',
            'title_type_id' => $titleType->id,
            'language' => null,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['titles'][0])
            ->toHaveKey('title')
            ->and($result['data']['attributes']['titles'][0]['title'])->toBe('Title without language')
            ->and($result['data']['attributes']['titles'][0])->not->toHaveKey('lang');
    });
});

describe('DataCiteJsonExporter - Descriptions', function () {
    test('exports descriptions with different types', function () {
        $resource = Resource::factory()->create();

        $abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );

        $methodsType = DescriptionType::firstOrCreate(
            ['slug' => 'Methods'],
            ['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true]
        );

        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Abstract text with at least 50 characters length',
            'description_type_id' => $abstractType->id,
            'language' => 'en',
        ]);

        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Methods description with at least 50 characters',
            'description_type_id' => $methodsType->id,
            'language' => 'en',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['descriptions'])->toHaveCount(2)
            ->and($result['data']['attributes']['descriptions'][0])->toMatchArray([
                'description' => 'Abstract text with at least 50 characters length',
                'descriptionType' => 'Abstract',
                'lang' => 'en',
            ])
            ->and($result['data']['attributes']['descriptions'][1])->toMatchArray([
                'description' => 'Methods description with at least 50 characters',
                'descriptionType' => 'Methods',
                'lang' => 'en',
            ]);
    });
});

describe('DataCiteJsonExporter - Rights', function () {
    test('exports rights with SPDX identifiers', function () {
        $resource = Resource::factory()->create();

        $right = Right::firstOrCreate(
            ['identifier' => 'GPL-3.0-or-later'],
            [
                'identifier' => 'GPL-3.0-or-later',
                'name' => 'GNU General Public License v3.0 or later',
                'uri' => 'https://spdx.org/licenses/GPL-3.0-or-later.html',
                'scheme_uri' => 'https://spdx.org/licenses/',
                'is_active' => true,
            ]
        );

        $resource->rights()->attach($right);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['rightsList'])->toHaveCount(1)
            ->and($result['data']['attributes']['rightsList'][0])
            ->toMatchArray([
                'rights' => 'GNU General Public License v3.0 or later',
                'rightsURI' => 'https://spdx.org/licenses/GPL-3.0-or-later.html',
                'rightsIdentifier' => 'GPL-3.0-or-later',
                'rightsIdentifierScheme' => 'SPDX',
                'schemeURI' => 'https://spdx.org/licenses/',
            ]);
    });

    test('handles right without SPDX data', function () {
        $resource = Resource::factory()->create();

        $right = Right::create([
            'identifier' => 'custom-license-'.fake()->unique()->randomNumber(5),
            'name' => 'Custom License',
            'uri' => null,
            'scheme_uri' => null,
            'is_active' => true,
        ]);

        $resource->rights()->attach($right);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['rightsList'][0])
            ->toMatchArray([
                'rights' => 'Custom License',
            ]);
    });
});

describe('DataCiteJsonExporter - Edge Cases', function () {
    test('handles resource with minimal required fields only', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        // Get or create Other type
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Other'],
            ['name' => 'Other', 'slug' => 'Other', 'is_active' => true]
        );
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add minimal title
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Minimal Resource',
            'title_type_id' => $titleType->id,
        ]);

        // Add minimal creator
        $person = Person::factory()->create([
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKeys([
            'data.attributes.titles',
            'data.attributes.creators',
            'data.attributes.publisher',
            'data.attributes.publicationYear',
            'data.attributes.types',
        ])
            ->and($result['data']['attributes'])->not->toHaveKey('subjects')
            ->and($result['data']['attributes'])->not->toHaveKey('contributors')
            ->and($result['data']['attributes'])->not->toHaveKey('descriptions');
    });

    test('handles resource with empty collections', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        // No subjects, no descriptions, no dates, etc.
        $result = $this->exporter->export($resource);

        expect($result['data']['attributes'])->not->toHaveKey('subjects')
            ->and($result['data']['attributes'])->not->toHaveKey('descriptions')
            ->and($result['data']['attributes'])->not->toHaveKey('dates')
            ->and($result['data']['attributes'])->not->toHaveKey('geoLocations')
            ->and($result['data']['attributes'])->not->toHaveKey('fundingReferences');
    });

    test('handles very long description text', function () {
        $resource = Resource::factory()->create();

        $longText = str_repeat('This is a very long description text. ', 100);

        $descriptionType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );

        Description::create([
            'resource_id' => $resource->id,
            'value' => $longText,
            'description_type_id' => $descriptionType->id,
            'language' => 'en',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['descriptions'][0]['description'])
            ->toBe($longText)
            ->and(strlen($result['data']['attributes']['descriptions'][0]['description']))
            ->toBeGreaterThan(3000);
    });

    test('handles special characters in names', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'François',
            'family_name' => 'Müller-Schmidt',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'][0]['name'])
            ->toBe('Müller-Schmidt, François')
            ->and($result['data']['attributes']['creators'][0]['givenName'])
            ->toBe('François')
            ->and($result['data']['attributes']['creators'][0]['familyName'])
            ->toBe('Müller-Schmidt');
    });

    test('validates JSON structure conforms to DataCite schema', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create();

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Title',
            'title_type_id' => $titleType->id,
        ]);

        $result = $this->exporter->export($resource);

        // Check top-level structure
        expect($result)->toHaveKey('data')
            ->and($result['data'])->toHaveKey('type')
            ->and($result['data']['type'])->toBe('dois')
            ->and($result['data'])->toHaveKey('attributes')
            ->and($result['data']['attributes'])->toBeArray();
    });
});
