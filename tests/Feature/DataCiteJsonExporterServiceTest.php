<?php

use App\Models\ContributorType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
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
            'name_identifier' => '0009-0000-1235-6950',
            'name_identifier_scheme' => 'ORCID',
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

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Test Dataset Software',
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
            ->and($result['data']['attributes']['publisher'])
            ->toMatchArray([
                'name' => 'GFZ Data Services',
            ]);
    });

    test('exports required publicationYear', function () {
        $resource = Resource::factory()->create([
            'publication_year' => 2024,
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.publicationYear')
            ->and($result['data']['attributes']['publicationYear'])->toBe(2024);
    });

    test('exports required resourceType', function () {
        $resource = Resource::factory()->create();
        $resourceType = ResourceType::where('name', 'Dataset')->first();
        $resource->resource_type_id = $resourceType?->id;
        $resource->save();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.types')
            ->and($result['data']['attributes']['types'])->toHaveKey('resourceType')
            ->and($result['data']['attributes']['types'])->toHaveKey('resourceTypeGeneral');
    });
});

describe('DataCiteJsonExporter - Contributors', function () {
    test('exports contributors with correct contributor type', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
        ]);

        $contactType = ContributorType::where('name', 'ContactPerson')->first();
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contactType?->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.contributors')
            ->and($result['data']['attributes']['contributors'])->toHaveCount(1)
            ->and($result['data']['attributes']['contributors'][0]['contributorType'])->toBe('ContactPerson');
    });
});

describe('DataCiteJsonExporter - Optional Fields', function () {
    test('exports language', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'de']);
        $resource->language_id = $language->id;
        $resource->save();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.language')
            ->and($result['data']['attributes']['language'])->toBe('de');
    });

    test('exports version', function () {
        $resource = Resource::factory()->create([
            'version' => '2.1.0',
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.version')
            ->and($result['data']['attributes']['version'])->toBe('2.1.0');
    });

    test('exports rights information', function () {
        $resource = Resource::factory()->create();
        $right = Right::factory()->create([
            'identifier' => 'CC-BY-4.0',
            'name' => 'Creative Commons Attribution 4.0',
            'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        ]);
        $resource->rights()->attach($right);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.rightsList')
            ->and($result['data']['attributes']['rightsList'])->toHaveCount(1)
            ->and($result['data']['attributes']['rightsList'][0]['rights'])->toBe('Creative Commons Attribution 4.0');
    });

    test('exports descriptions', function () {
        $resource = Resource::factory()->create();
        $abstractType = DescriptionType::where('slug', 'Abstract')->first();

        Description::create([
            'resource_id' => $resource->id,
            'description' => 'This is a test abstract.',
            'description_type_id' => $abstractType?->id,
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.descriptions')
            ->and($result['data']['attributes']['descriptions'])->toHaveCount(1)
            ->and($result['data']['attributes']['descriptions'][0]['description'])->toBe('This is a test abstract.')
            ->and($result['data']['attributes']['descriptions'][0]['descriptionType'])->toBe('Abstract');
    });
});

describe('DataCiteJsonExporter - Institution as Creator', function () {
    test('exports institution as organizational creator', function () {
        $resource = Resource::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'GFZ German Research Centre for Geosciences',
            'ror_id' => 'https://ror.org/04z8jg394',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.creators')
            ->and($result['data']['attributes']['creators'])->toHaveCount(1)
            ->and($result['data']['attributes']['creators'][0]['nameType'])->toBe('Organizational')
            ->and($result['data']['attributes']['creators'][0]['name'])->toBe('GFZ German Research Centre for Geosciences');
    });
});

describe('DataCiteJsonExporter - Multiple Creators', function () {
    test('exports multiple creators in correct order', function () {
        $resource = Resource::factory()->create();

        $person1 = Person::factory()->create(['given_name' => 'Alice', 'family_name' => 'Smith']);
        $person2 = Person::factory()->create(['given_name' => 'Bob', 'family_name' => 'Jones']);
        $person3 = Person::factory()->create(['given_name' => 'Charlie', 'family_name' => 'Brown']);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person1->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person2->id,
            'creatorable_type' => Person::class,
            'position' => 2,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person3->id,
            'creatorable_type' => Person::class,
            'position' => 3,
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'])->toHaveCount(3)
            ->and($result['data']['attributes']['creators'][0]['name'])->toBe('Smith, Alice')
            ->and($result['data']['attributes']['creators'][1]['name'])->toBe('Jones, Bob')
            ->and($result['data']['attributes']['creators'][2]['name'])->toBe('Brown, Charlie');
    });
});
