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
use App\Services\DataCiteXmlExporter;

beforeEach(function () {
    $this->exporter = new DataCiteXmlExporter;
});

describe('DataCiteXmlExporter - Required Fields', function () {
    test('exports valid XML with required elements', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/B09Z-4K37',
            'year' => 2024,
        ]);

        $resourceType = ResourceType::factory()->create(['name' => 'Dataset']);
        $resource->resourceType()->associate($resourceType);
        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toBeString()
            ->and($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
            ->and($xml)->toContain('<resource xmlns="http://datacite.org/schema/kernel-4"')
            ->and($xml)->toContain('<identifier identifierType="DOI">10.82433/B09Z-4K37</identifier>');
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

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creators>')
            ->and($xml)->toContain('<creator>')
            ->and($xml)->toContain('<creatorName nameType="Personal">Ehrmann, Holger</creatorName>')
            ->and($xml)->toContain('<givenName>Holger</givenName>')
            ->and($xml)->toContain('<familyName>Ehrmann</familyName>')
            ->and($xml)->toContain('<nameIdentifier nameIdentifierScheme="ORCID"');
    });

    test('exports required titles', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);
        $resource->save();

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Test Dataset Software',
            'language_id' => $language->id,
            'title_type' => null,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<titles>')
            ->and($xml)->toContain('<title xml:lang="en">Test Dataset Software</title>');
    });

    test('exports required publisher', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publisher')
            ->and($xml)->toContain('publisherIdentifier="https://ror.org/04z8jg394"')
            ->and($xml)->toContain('publisherIdentifierScheme="ROR"')
            ->and($xml)->toContain('>GFZ Helmholtz Centre for Geosciences</publisher>');
    });

    test('exports required publication year', function () {
        $resource = Resource::factory()->create(['year' => 2024]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publicationYear>2024</publicationYear>');
    });

    test('exports required resource type', function () {
        $resource = Resource::factory()->create();
        $resourceType = ResourceType::factory()->create(['name' => 'Dataset']);
        $resource->resourceType()->associate($resourceType);
        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>');
    });

    test('handles missing creators with default', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creators>')
            ->and($xml)->toContain('<creatorName nameType="Personal">Unknown</creatorName>');
    });
});

describe('DataCiteXmlExporter - Creators & Contributors', function () {
    test('exports person creator with ORCID', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'orcid' => '0000-0002-1825-0097',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">0000-0002-1825-0097</nameIdentifier>');
    });

    test('exports institutional creator with ROR', function () {
        $resource = Resource::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'Test University',
            'identifier_type' => 'ROR',
            'identifier' => 'https://ror.org/12345',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $institution->id,
            'authorable_type' => Institution::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creatorName nameType="Organizational">Test University</creatorName>')
            ->and($xml)->toContain('<nameIdentifier nameIdentifierScheme="ROR" schemeURI="https://ror.org">https://ror.org/12345</nameIdentifier>');
    });

    test('exports contributors', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Editor',
        ]);

        $editorRole = Role::where('name', 'Editor')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 2,
        ]);
        $resourceAuthor->roles()->attach($editorRole);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<contributors>')
            ->and($xml)->toContain('<contributor contributorType="Editor">')
            ->and($xml)->toContain('<contributorName nameType="Personal">Editor, John</contributorName>');
    });
});

describe('DataCiteXmlExporter - Optional Fields', function () {
    test('exports subjects from keywords', function () {
        $resource = Resource::factory()->create();
        $resource->keywords()->create(['keyword' => 'geology']);
        $resource->keywords()->create(['keyword' => 'climate']);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<subjects>')
            ->and($xml)->toContain('<subject>geology</subject>')
            ->and($xml)->toContain('<subject>climate</subject>');
    });

    test('exports dates', function () {
        $resource = Resource::factory()->create();
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type' => 'collected',
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<dates>')
            ->and($xml)->toContain('<date dateType="Collected">2023-01-01/2023-12-31</date>');
    });

    test('exports language', function () {
        $resource = Resource::factory()->create();
        $language = Language::factory()->create(['iso_code' => 'de']);
        $resource->language()->associate($language);
        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<language>de</language>');
    });

    test('exports version', function () {
        $resource = Resource::factory()->create(['version' => '2.1']);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<version>2.1</version>');
    });

    test('exports rights list', function () {
        $resource = Resource::factory()->create();
        $license = License::factory()->create([
            'name' => 'CC BY 4.0',
            'reference' => 'https://creativecommons.org/licenses/by/4.0/',
            'spdx_id' => 'CC-BY-4.0',
        ]);
        $resource->licenses()->attach($license);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<rightsList>')
            ->and($xml)->toContain('<rights')
            ->and($xml)->toContain('rightsURI="https://creativecommons.org/licenses/by/4.0/"')
            ->and($xml)->toContain('rightsIdentifier="CC-BY-4.0"')
            ->and($xml)->toContain('>CC BY 4.0</rights>');
    });

    test('exports descriptions', function () {
        $resource = Resource::factory()->create();
        ResourceDescription::create([
            'resource_id' => $resource->id,
            'description' => 'This is an abstract',
            'description_type' => 'abstract',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<descriptions>')
            ->and($xml)->toContain('<description descriptionType="Abstract">This is an abstract</description>');
    });

    test('exports geo locations', function () {
        $resource = Resource::factory()->create();
        $resource->coverages()->create([
            'description' => 'Berlin, Germany',
            'lat_min' => 52.3,
            'lat_max' => 52.7,
            'lon_min' => 13.0,
            'lon_max' => 13.8,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<geoLocations>')
            ->and($xml)->toContain('<geoLocation>')
            ->and($xml)->toContain('<geoLocationPlace>Berlin, Germany</geoLocationPlace>')
            ->and($xml)->toContain('<geoLocationBox>')
            ->and($xml)->toContain('<westBoundLongitude>13</westBoundLongitude>')
            ->and($xml)->toContain('<eastBoundLongitude>13.8</eastBoundLongitude>');
    });
});

describe('DataCiteXmlExporter - XML Structure', function () {
    test('validates well-formed XML', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        // Try to parse XML - should not throw exception
        $dom = new DOMDocument;
        $result = $dom->loadXML($xml);

        expect($result)->toBeTrue()
            ->and($dom->documentElement->nodeName)->toBe('resource');
    });

    test('includes correct namespaces', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('xmlns="http://datacite.org/schema/kernel-4"')
            ->and($xml)->toContain('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"')
            ->and($xml)->toContain('xsi:schemaLocation="http://datacite.org/schema/kernel-4 https://schema.datacite.org/meta/kernel-4.6/metadata.xsd"');
    });

    test('escapes special XML characters', function () {
        $resource = Resource::factory()->create();
        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Test & Special <Characters> "Quotes"',
        ]);

        $xml = $this->exporter->export($resource);

        // XML should contain escaped characters
        expect($xml)->toContain('&amp;')
            ->and($xml)->toContain('&lt;')
            ->and($xml)->toContain('&gt;');
    });
});

describe('DataCiteXmlExporter - Edge Cases', function () {
    test('handles resource with minimal data', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.1234/test',
            'year' => 2024,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toBeString()
            ->and(strlen($xml))->toBeGreaterThan(100);
    });

    test('handles resource with maximum data', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.1234/full-test',
            'year' => 2024,
            'version' => '1.0',
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);

        // Add all possible related data
        ResourceTitle::create(['resource_id' => $resource->id]);

        $person = Person::factory()->create();
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $resource->keywords()->create(['keyword' => 'test']);
        ResourceDescription::create(['resource_id' => $resource->id]);
        ResourceDate::create(['resource_id' => $resource->id]);

        $license = License::factory()->create();
        $resource->licenses()->attach($license);

        $resource->save();

        $xml = $this->exporter->export($resource);

        expect($xml)->toBeString()
            ->and($xml)->toContain('<identifier')
            ->and($xml)->toContain('<creators>')
            ->and($xml)->toContain('<titles>')
            ->and($xml)->toContain('<publisher')
            ->and($xml)->toContain('<publicationYear>')
            ->and($xml)->toContain('<resourceType');
    });
});
