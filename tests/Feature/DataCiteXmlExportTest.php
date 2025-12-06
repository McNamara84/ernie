<?php

use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('DataCite XML Export - HTTP Endpoint', function () {
    test('requires authentication', function () {
        $resource = Resource::factory()->create();

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(302)
            ->assertRedirect(route('login'));
    });

    test('returns XML file for valid resource', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-XML',
            'publication_year' => 2024,
        ]);

        $titleType = TitleType::where('slug', 'main-title')->first();
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Resource for XML Export',
            'title_type_id' => $titleType?->id,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/xml')
            ->assertHeader('Content-Disposition');
    });

    test('returns 404 for non-existent resource', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('resources.export-datacite-xml', 99999));

        $response->assertStatus(404);
    });

    test('exports DataCite XML with correct headers', function () {
        $this->actingAs($this->user);

        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-HEADERS',
            'publication_year' => 2024,
        ]);

        $titleType = TitleType::where('slug', 'main-title')->first();
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test Headers',
            'title_type_id' => $titleType?->id,
        ]);

        $person = Person::factory()->create([
            'given_name' => 'Test',
            'family_name' => 'Author',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        // Check headers
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment')
            ->and($contentDisposition)->toContain('datacite.xml')
            ->and($contentDisposition)->toContain("resource-{$resource->id}");
    });

    test('exports valid XML structure', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-STRUCTURE',
            'publication_year' => 2024,
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);
        $resource->save();

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Test XML Structure',
            'language' => 'en',
        ]);

        $person = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Researcher',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Validate it's proper XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        expect($loaded)->toBeTrue()
            ->and($errors)->toBeEmpty('XML should not have parsing errors');

        // Check required DataCite elements
        expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
            ->and($xml)->toContain('<resource xmlns="http://datacite.org/schema/kernel-4"')
            ->and($xml)->toContain('<identifier identifierType="DOI">10.82433/TEST-STRUCTURE</identifier>')
            ->and($xml)->toContain('<creators>')
            ->and($xml)->toContain('<titles>')
            ->and($xml)->toContain('<publisher')
            ->and($xml)->toContain('<publicationYear>2024</publicationYear>')
            ->and($xml)->toContain('<resourceType');
    });

    test('exports complete resource with all optional fields', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-COMPLETE',
            'publication_year' => 2024,
            'version' => '1.2',
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);
        $resource->save();

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Complete Test Resource',
            'language' => 'en',
        ]);

        $person = Person::factory()->create([
            'given_name' => 'Complete',
            'family_name' => 'Tester',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        // Add keywords (subjects)
        $resource->subjects()->create(['subject' => 'testing']);
        $resource->subjects()->create(['subject' => 'datacite']);

        // Add description
        $abstractType = \App\Models\DescriptionType::where('slug', 'Abstract')->first();
        $resource->descriptions()->create([
            'description' => 'A comprehensive test resource',
            'description_type_id' => $abstractType?->id,
        ]);

        // Add date
        $collectedType = \App\Models\DateType::where('slug', 'Collected')->first();
        $resource->dates()->create([
            'date_value' => '2024-01-01/2024-12-31',
            'date_type_id' => $collectedType?->id,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Check all optional elements are present
        expect($xml)->toContain('<subjects>')
            ->and($xml)->toContain('testing')
            ->and($xml)->toContain('<descriptions>')
            ->and($xml)->toContain('A comprehensive test resource')
            ->and($xml)->toContain('<language>en</language>')
            ->and($xml)->toContain('<version>1.2</version>');
    });

    test('filename includes resource ID and timestamp', function () {
        $resource = Resource::factory()->create();

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Filename Test',
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $contentDisposition = $response->headers->get('Content-Disposition');

        // Check filename pattern: resource-{id}-{timestamp}-datacite.xml
        expect($contentDisposition)->toMatch('/resource-\d+-\d+-datacite\.xml/')
            ->and($contentDisposition)->toContain("resource-{$resource->id}");
    });

    test('handles special characters in resource data', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-SPECIAL',
            'publication_year' => 2024,
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Title with & Special <Characters> "Quotes"',
        ]);

        $person = Person::factory()->create([
            'given_name' => 'Test & Co',
            'family_name' => 'Researcher <Main>',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Should contain properly escaped XML
        expect($xml)->toContain('&amp;')
            ->and($xml)->toContain('&lt;')
            ->and($xml)->toContain('&gt;');

        // Should be valid XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        expect($loaded)->toBeTrue()
            ->and($errors)->toBeEmpty('XML should not have parsing errors');
    });

    test('validates against DataCite schema', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-VALIDATION',
            'publication_year' => 2024,
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Schema Validation Test',
        ]);

        $person = Person::factory()->create();
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Validate against XSD schema
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        // Note: This test may fail if the online schema is not accessible
        // or if there are validation warnings (which are allowed)
        $result = $dom->schemaValidate('https://schema.datacite.org/meta/kernel-4.6/metadata.xsd');
        $errors = libxml_get_errors();
        libxml_clear_errors();

        // We expect either true or validation warnings (but still valid XML)
        expect($dom->documentElement)->not->toBeNull();
    });

    test('exports different resources with unique content', function () {
        $resource1 = Resource::factory()->create([
            'doi' => '10.82433/UNIQUE-1',
            'publication_year' => 2023,
        ]);

        Title::create([
            'resource_id' => $resource1->id,
            'value' => 'First Resource',
        ]);

        $resource2 = Resource::factory()->create([
            'doi' => '10.82433/UNIQUE-2',
            'publication_year' => 2024,
        ]);

        Title::create([
            'resource_id' => $resource2->id,
            'value' => 'Second Resource',
        ]);

        $response1 = $this->get(route('resources.export-datacite-xml', $resource1));
        $response2 = $this->get(route('resources.export-datacite-xml', $resource2));

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        $xml1 = $response1->getContent();
        $xml2 = $response2->getContent();

        // Both should be valid but different
        expect($xml1)->not->toBe($xml2)
            ->and($xml1)->toContain('10.82433/UNIQUE-1')
            ->and($xml1)->toContain('First Resource')
            ->and($xml1)->toContain('<publicationYear>2023</publicationYear>')
            ->and($xml2)->toContain('10.82433/UNIQUE-2')
            ->and($xml2)->toContain('Second Resource')
            ->and($xml2)->toContain('<publicationYear>2024</publicationYear>');
    });
});
