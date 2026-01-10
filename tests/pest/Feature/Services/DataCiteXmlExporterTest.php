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
use App\Services\DataCiteXmlExporter;

beforeEach(function () {
    $this->exporter = new DataCiteXmlExporter;
});

describe('DataCiteXmlExporter - XML Structure', function () {
    test('exports valid XML with proper declaration', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toBeString()
            ->and($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>');
    });

    test('exports valid DataCite namespace and schema location', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('xmlns="http://datacite.org/schema/kernel-4"')
            ->and($xml)->toContain('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"')
            ->and($xml)->toContain('xsi:schemaLocation="http://datacite.org/schema/kernel-4 https://schema.datacite.org/meta/kernel-4.6/metadata.xsd"');
    });

    test('exports well-formed XML that can be parsed', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        $dom = new DOMDocument;
        $result = $dom->loadXML($xml);

        expect($result)->toBeTrue();
    });
});

describe('DataCiteXmlExporter - Required Fields', function () {
    test('exports required identifier (DOI)', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/B09Z-4K37',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<identifier identifierType="DOI">10.82433/B09Z-4K37</identifier>');
    });

    test('handles missing DOI gracefully', function () {
        $resource = Resource::factory()->create(['doi' => null]);

        $xml = $this->exporter->export($resource);

        // When DOI is null, it should still have the identifier element (self-closing or empty)
        expect($xml)->toMatch('/<identifier identifierType="DOI"(\/>|><\/identifier>)/');
    });

    test('exports required creators with person', function () {
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

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creators>')
            ->and($xml)->toContain('<creatorName nameType="Personal">Ehrmann, Holger</creatorName>')
            ->and($xml)->toContain('<givenName>Holger</givenName>')
            ->and($xml)->toContain('<familyName>Ehrmann</familyName>')
            ->and($xml)->toContain('nameIdentifierScheme="ORCID"')
            ->and($xml)->toContain('https://orcid.org/0009-0000-1235-6950</nameIdentifier>');
    });

    test('exports default creator when none provided', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creatorName nameType="Personal">Unknown</creatorName>');
    });

    test('exports required titles', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Dataset for Climate Research',
            'title_type_id' => $titleType->id,
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<titles>')
            ->and($xml)->toContain('<title')
            ->and($xml)->toContain('Test Dataset for Climate Research</title>');
    });

    test('exports default title when none provided', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<title>Untitled</title>');
    });

    test('exports required publisher', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publisher')
            ->and($xml)->toContain('GFZ Data Services</publisher>');
    });

    test('exports required publicationYear', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<publicationYear>2024</publicationYear>');
    });

    test('exports required resourceType', function () {
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $resourceType->id]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>');
    });
});

describe('DataCiteXmlExporter - Creators & Contributors', function () {
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

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<creatorName nameType="Organizational">Library and Information Services</creatorName>')
            ->and($xml)->toContain('nameIdentifierScheme="ROR"')
            ->and($xml)->toContain('https://ror.org/04z8jg394</nameIdentifier>');
    });

    test('exports contributor with correct type', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
        ]);

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

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<contributors>')
            ->and($xml)->toContain('<contributor contributorType="ContactPerson">')
            ->and($xml)->toContain('<contributorName nameType="Personal">Doe, Jane</contributorName>');
    });

    test('exports multiple creators in correct order', function () {
        $resource = Resource::factory()->create();

        $person1 = Person::factory()->create(['given_name' => 'First', 'family_name' => 'Author']);
        $person2 = Person::factory()->create(['given_name' => 'Second', 'family_name' => 'Author']);

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

        $xml = $this->exporter->export($resource);

        $pos1 = strpos($xml, 'Author, First');
        $pos2 = strpos($xml, 'Author, Second');

        expect($pos1)->toBeLessThan($pos2);
    });
});

describe('DataCiteXmlExporter - Titles', function () {
    test('exports subtitle with titleType attribute', function () {
        $resource = Resource::factory()->create();

        $mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        $subtitleType = TitleType::firstOrCreate(
            ['slug' => 'Subtitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Main Title',
            'title_type_id' => $mainTitleType->id,
            'language' => 'en',
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'A Detailed Subtitle',
            'title_type_id' => $subtitleType->id,
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('titleType="Subtitle"')
            ->and($xml)->toContain('A Detailed Subtitle</title>');
    });

    test('exports alternative title with titleType attribute', function () {
        $resource = Resource::factory()->create();

        $altTitleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Alternative Name',
            'title_type_id' => $altTitleType->id,
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('titleType="AlternativeTitle"')
            ->and($xml)->toContain('Alternative Name</title>');
    });
});

describe('DataCiteXmlExporter - Descriptions', function () {
    test('exports abstract description', function () {
        $resource = Resource::factory()->create();

        $abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );

        Description::create([
            'resource_id' => $resource->id,
            'value' => 'This is the abstract of the dataset.',
            'description_type_id' => $abstractType->id,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<descriptions>')
            ->and($xml)->toContain('descriptionType="Abstract"')
            ->and($xml)->toContain('This is the abstract of the dataset.</description>');
    });

    test('exports methods description', function () {
        $resource = Resource::factory()->create();

        $methodsType = DescriptionType::firstOrCreate(
            ['slug' => 'Methods'],
            ['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true]
        );

        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Methodology used in data collection.',
            'description_type_id' => $methodsType->id,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('descriptionType="Methods"')
            ->and($xml)->toContain('Methodology used in data collection.</description>');
    });
});

describe('DataCiteXmlExporter - Subjects', function () {
    test('exports subject with scheme', function () {
        $resource = Resource::factory()->create();

        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'Climate Science',
            'subject_scheme' => 'NASA/GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => null,
            'classification_code' => null,
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<subjects>')
            ->and($xml)->toContain('subjectScheme="NASA/GCMD Science Keywords"')
            ->and($xml)->toContain('>Climate Science</subject>');
    });

    test('exports subject with classification code', function () {
        $resource = Resource::factory()->create();

        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'Geophysics',
            'subject_scheme' => 'DDC',
            'scheme_uri' => 'https://dewey.info/',
            'value_uri' => null,
            'classification_code' => '550',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('classificationCode="550"')
            ->and($xml)->toContain('>Geophysics</subject>');
    });
});

describe('DataCiteXmlExporter - Rights', function () {
    test('exports rights with identifier', function () {
        $resource = Resource::factory()->create();

        $right = Right::firstOrCreate(
            ['identifier' => 'CC-BY-4.0'],
            [
                'name' => 'Creative Commons Attribution 4.0 International',
                'identifier' => 'CC-BY-4.0',
                'scheme_uri' => 'https://spdx.org/licenses/',
                'uri' => 'https://creativecommons.org/licenses/by/4.0/',
                'is_active' => true,
            ]
        );

        $resource->rights()->attach($right->id);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('<rightsList>')
            ->and($xml)->toContain('rightsIdentifier="CC-BY-4.0"')
            ->and($xml)->toContain('rightsURI="https://creativecommons.org/licenses/by/4.0/"')
            ->and($xml)->toContain('Creative Commons Attribution 4.0 International</rights>');
    });
});

describe('DataCiteXmlExporter - Edge Cases', function () {
    test('escapes special XML characters', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Data & Analysis <Test> "Results"',
            'title_type_id' => $titleType->id,
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('Data &amp; Analysis &lt;Test&gt; "Results"');
    });

    test('handles resource with all optional fields empty', function () {
        $resource = Resource::factory()->create();

        $xml = $this->exporter->export($resource);

        // Should still produce valid XML with required fields
        $dom = new DOMDocument;
        $result = $dom->loadXML($xml);

        expect($result)->toBeTrue()
            ->and($xml)->toContain('<identifier')
            ->and($xml)->toContain('<creators>')
            ->and($xml)->toContain('<titles>')
            ->and($xml)->toContain('<publisher')
            ->and($xml)->toContain('<publicationYear')
            ->and($xml)->toContain('<resourceType');
    });

    test('handles moderately long title', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        // Use a title that fits within database constraints (max ~255 chars)
        $longTitle = str_repeat('Long Title ', 20);

        Title::create([
            'resource_id' => $resource->id,
            'value' => $longTitle,
            'title_type_id' => $titleType->id,
            'language' => 'en',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain($longTitle);
    });

    test('handles unicode characters', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Datenanalyse für Klimaforschung – 日本語テスト',
            'title_type_id' => $titleType->id,
            'language' => 'de',
        ]);

        $xml = $this->exporter->export($resource);

        expect($xml)->toContain('Datenanalyse für Klimaforschung – 日本語テスト');
    });
});
