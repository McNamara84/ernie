<?php

use App\Models\Institution;
use App\Models\Language;
use App\Models\License;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceDate;
use App\Models\ResourceDescription;
use App\Models\ResourceTitle;
use App\Models\ResourceType;
use App\Models\Role;
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
            'first_name' => 'Holger',
            'last_name' => 'Ehrmann',
            'orcid' => '0009-0000-1235-6950',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
        $language = Language::factory()->create(['iso_code' => 'en']);

        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Test Dataset Software',
            'language_id' => $language->id,
            'title_type' => null,
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
                'name' => 'GFZ Helmholtz Centre for Geosciences',
                'publisherIdentifier' => 'https://ror.org/04z8jg394',
                'publisherIdentifierScheme' => 'ROR',
                'schemeUri' => 'https://ror.org/',
            ]);
    });

    test('exports required publicationYear', function () {
        $resource = Resource::factory()->create(['year' => 2024]);

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('data.attributes.publicationYear')
            ->and($result['data']['attributes']['publicationYear'])->toBe(2024);
    });

    test('exports required resourceType', function () {
        $resourceType = ResourceType::where('name', 'Dataset')->first();
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
    test('distinguishes between creators and contributors by role', function () {
        $resource = Resource::factory()->create();

        // Create person as both creator and contributor
        $person = Person::factory()->create([
            'first_name' => 'Holger',
            'last_name' => 'Ehrmann',
            'orcid' => '0009-0000-1235-6950',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $contactRole = Role::where('name', 'Contact Person')->first();

        // Add as creator (Author role)
        $creator = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $creator->roles()->attach($authorRole);

        // Add as contributor (Contact Person role)
        $contributor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 2,
        ]);
        $contributor->roles()->attach($contactRole);

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
            'ror_id' => 'https://ror.org/04z8jg394',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $institution->id,
            'authorable_type' => Institution::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
                'schemeUri' => 'https://ror.org',
            ]);
    });

    test('exports contributor with correct contributorType mapping', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'first_name' => 'Ali',
            'last_name' => 'Mohammed',
        ]);

        $dataCollectorRole = Role::where('name', 'Data Collector')->first();
        $contributor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $contributor->roles()->attach($dataCollectorRole);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['contributors'][0])
            ->toHaveKey('contributorType')
            ->and($result['data']['attributes']['contributors'][0]['contributorType'])
            ->toBe('DataCollector');
    });

    test('handles creator with only last name', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'first_name' => null,
            'last_name' => 'Einstein',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['creators'][0])
            ->toMatchArray([
                'name' => 'Einstein',
                'familyName' => 'Einstein',
            ])
            ->and($result['data']['attributes']['creators'][0])->not->toHaveKey('givenName');
    });

    test('handles creator with only first name', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'first_name' => 'Madonna',
            'last_name' => null,
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
        $language = Language::factory()->create(['iso_code' => 'en']);

        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Main Title',
            'language_id' => $language->id,
            'title_type' => null,
        ]);

        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Alternative Title',
            'language_id' => $language->id,
            'title_type' => 'AlternativeTitle',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['titles'])->toHaveCount(2)
            ->and($result['data']['attributes']['titles'][0])->toMatchArray([
                'title' => 'Main Title',
                'lang' => 'en',
            ])
            ->and($result['data']['attributes']['titles'][0])->not->toHaveKey('titleType')
            ->and($result['data']['attributes']['titles'][1])->toMatchArray([
                'title' => 'Alternative Title',
                'titleType' => 'AlternativeTitle',
                'lang' => 'en',
            ]);
    });

    test('handles title without language', function () {
        $resource = Resource::factory()->create();

        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Title without language',
            'language_id' => null,
            'title_type' => null,
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
        $language = Language::factory()->create(['iso_code' => 'en']);

        ResourceDescription::factory()->create([
            'resource_id' => $resource->id,
            'description' => 'Abstract text with at least 50 characters length',
            'description_type' => 'Abstract',
            'language_id' => $language->id,
        ]);

        ResourceDescription::factory()->create([
            'resource_id' => $resource->id,
            'description' => 'Methods description with at least 50 characters',
            'description_type' => 'Methods',
            'language_id' => $language->id,
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

describe('DataCiteJsonExporter - Dates', function () {
    test('exports dates with different types', function () {
        $resource = Resource::factory()->create();

        ResourceDate::factory()->create([
            'resource_id' => $resource->id,
            'date_type' => 'created',
            'start_date' => '2024-01-01 00:00:00',
            'end_date' => null,
        ]);

        ResourceDate::factory()->create([
            'resource_id' => $resource->id,
            'date_type' => 'collected',
            'start_date' => '2024-01-01 00:00:00',
            'end_date' => '2024-12-31 00:00:00',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['dates'])->toHaveCount(2)
            ->and($result['data']['attributes']['dates'][0])->toMatchArray([
                'dateType' => 'Created',
                'date' => '2024-01-01 00:00:00',
            ])
            ->and($result['data']['attributes']['dates'][1])->toMatchArray([
                'dateType' => 'Collected',
                'date' => '2024-01-01 00:00:00/2024-12-31 00:00:00',
            ]);
    });

    test('exports date with dateInformation for Other type', function () {
        $resource = Resource::factory()->create();

        ResourceDate::factory()->create([
            'resource_id' => $resource->id,
            'date_type' => 'other',
            'start_date' => '2024-01-01 00:00:00',
            'date_information' => 'Custom date information',
        ]);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['dates'][0])
            ->toMatchArray([
                'dateType' => 'Other',
                'date' => '2024-01-01 00:00:00',
                'dateInformation' => 'Custom date information',
            ]);
    });
});

describe('DataCiteJsonExporter - Rights', function () {
    test('exports rights with SPDX identifiers', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language_id = $language->id;
        $resource->save();

        $license = License::factory()->create([
            'name' => 'GNU General Public License v3.0 or later',
            'spdx_id' => 'GPL-3.0-or-later',
            'reference' => 'https://spdx.org/licenses/GPL-3.0-or-later.html',
        ]);

        $resource->licenses()->attach($license);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['rightsList'])->toHaveCount(1)
            ->and($result['data']['attributes']['rightsList'][0])
            ->toMatchArray([
                'rights' => 'GNU General Public License v3.0 or later',
                'rightsURI' => 'https://spdx.org/licenses/GPL-3.0-or-later.html',
                'rightsIdentifier' => 'GPL-3.0-or-later',
                'rightsIdentifierScheme' => 'SPDX',
                'schemeURI' => 'https://spdx.org/licenses/',
                'lang' => 'en',
            ]);
    });

    test('handles license without SPDX data', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language_id = $language->id;
        $resource->save();

        $license = License::factory()->create([
            'name' => 'Custom License',
            'spdx_id' => null,
            'reference' => null,
        ]);

        $resource->licenses()->attach($license);

        $result = $this->exporter->export($resource);

        expect($result['data']['attributes']['rightsList'][0])
            ->toMatchArray([
                'rights' => 'Custom License',
                'lang' => 'en',
            ])
            ->and($result['data']['attributes']['rightsList'][0])->not->toHaveKey('rightsURI')
            ->and($result['data']['attributes']['rightsList'][0])->not->toHaveKey('rightsIdentifier');
    });
});

describe('DataCiteJsonExporter - Edge Cases', function () {
    test('handles resource with minimal required fields only', function () {
        $resource = Resource::factory()->create(['year' => 2024]);
        $resourceType = ResourceType::where('name', 'Other')->first();
        $resource->resource_type_id = $resourceType->id;
        $resource->save();

        // Add minimal title
        ResourceTitle::factory()->create([
            'resource_id' => $resource->id,
            'title' => 'Minimal Resource',
        ]);

        // Add minimal creator
        $person = Person::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
        $resource = Resource::factory()->create(['year' => 2024]);

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
        $language = Language::factory()->create(['iso_code' => 'en']);

        $longText = str_repeat('This is a very long description text. ', 100);

        ResourceDescription::factory()->create([
            'resource_id' => $resource->id,
            'description' => $longText,
            'description_type' => 'Abstract',
            'language_id' => $language->id,
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
            'first_name' => 'François',
            'last_name' => 'Müller-Schmidt',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
        $authorRole = Role::where('name', 'Author')->first();

        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        ResourceTitle::factory()->create(['resource_id' => $resource->id]);

        $result = $this->exporter->export($resource);

        // Check top-level structure
        expect($result)->toHaveKey('data')
            ->and($result['data'])->toHaveKey('type')
            ->and($result['data']['type'])->toBe('dois')
            ->and($result['data'])->toHaveKey('attributes')
            ->and($result['data']['attributes'])->toBeArray();
    });
});
