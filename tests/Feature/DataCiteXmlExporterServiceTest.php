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
use App\Services\DataCiteXmlExporter;

beforeEach(function () {
    $this->exporter = new DataCiteXmlExporter;
});

describe('DataCiteXmlExporter - Required Fields', function () {
    test('exports valid XML structure', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-XML',
            'publication_year' => 2024,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
            ->and($xml)->toContain('<resource xmlns="http://datacite.org/schema/kernel-4"')
            ->and($xml)->toContain('<identifier identifierType="DOI">10.82433/TEST-XML</identifier>');

        // Validate it's proper XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        expect($loaded)->toBeTrue()
            ->and($errors)->toBeEmpty();
    });

    test('exports creators', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
            'name_identifier' => '0000-0002-1234-5678',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creators>')
            ->and($xml)->toContain('<creatorName nameType="Personal">Doe, Jane</creatorName>')
            ->and($xml)->toContain('<givenName>Jane</givenName>')
            ->and($xml)->toContain('<familyName>Doe</familyName>');
    });

    test('exports titles', function () {
        $resource = Resource::factory()->create();

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Test Dataset Title',
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<titles>')
            ->and($xml)->toContain('Test Dataset Title');
    });

    test('exports publisher', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publisher')
            ->and($xml)->toContain('GFZ Data Services');
    });

    test('exports publicationYear', function () {
        $resource = Resource::factory()->create([
            'publication_year' => 2024,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publicationYear>2024</publicationYear>');
    });

    test('exports resourceType', function () {
        $resource = Resource::factory()->create();
        $resourceType = ResourceType::where('name', 'Dataset')->first();
        $resource->resource_type_id = $resourceType?->id;
        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<resourceType');
    });
});

describe('DataCiteXmlExporter - Contributors', function () {
    test('exports contributors', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Contact',
            'family_name' => 'Person',
        ]);

        $contactType = ContributorType::where('name', 'ContactPerson')->first();
        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contactType?->id,
            'position' => 1,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<contributors>')
            ->and($xml)->toContain('contributorType="ContactPerson"');
    });
});

describe('DataCiteXmlExporter - Optional Fields', function () {
    test('exports language', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'de']);
        $resource->language_id = $language->id;
        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<language>de</language>');
    });

    test('exports version', function () {
        $resource = Resource::factory()->create([
            'version' => '1.2.3',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<version>1.2.3</version>');
    });

    test('exports rights', function () {
        $resource = Resource::factory()->create();
        $right = Right::factory()->create([
            'identifier' => 'CC-BY-4.0',
            'name' => 'Creative Commons Attribution 4.0',
            'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        ]);
        $resource->rights()->attach($right);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<rightsList>')
            ->and($xml)->toContain('Creative Commons Attribution 4.0');
    });

    test('exports descriptions', function () {
        $resource = Resource::factory()->create();
        $abstractType = DescriptionType::where('slug', 'Abstract')->first();

        Description::create([
            'resource_id' => $resource->id,
            'description' => 'This is a test abstract.',
            'description_type_id' => $abstractType?->id,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<descriptions>')
            ->and($xml)->toContain('This is a test abstract.')
            ->and($xml)->toContain('descriptionType="Abstract"');
    });
});

describe('DataCiteXmlExporter - Institution as Creator', function () {
    test('exports institution as organizational creator', function () {
        $resource = Resource::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'GFZ Potsdam',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => 1,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creators>')
            ->and($xml)->toContain('nameType="Organizational"')
            ->and($xml)->toContain('GFZ Potsdam');
    });
});

describe('DataCiteXmlExporter - Special Characters', function () {
    test('properly escapes XML special characters', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Test & Co',
            'family_name' => 'Author <Main>',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $xml = $this->exporter->export($resource);

        // Should contain properly escaped XML
        expect($xml)->toContain('&amp;')
            ->and($xml)->toContain('&lt;')
            ->and($xml)->toContain('&gt;');

        // Should still be valid XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        expect($loaded)->toBeTrue()
            ->and($errors)->toBeEmpty();
    });
});
