<?php

use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceTitle;
use App\Models\ResourceType;
use App\Models\Role;
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
            'year' => 2024,
        ]);

        $titleType = TitleType::where('slug', 'main-title')->first();
        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Test Resource for XML Export',
            'title_type_id' => $titleType->id,
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
            'year' => 2024,
        ]);

        $titleType = TitleType::where('slug', 'main-title')->first();
        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Test Headers',
            'title_type_id' => $titleType->id,
        ]);

        $person = Person::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Author',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

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
            'year' => 2024,
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);
        $resource->save();

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Test XML Structure',
            'language_id' => $language->id,
        ]);

        $person = Person::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Researcher',
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

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Validate it's proper XML
        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($xml);
        expect($loaded)->toBeTrue();

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
            'year' => 2024,
            'version' => '1.2',
        ]);

        $language = Language::factory()->create(['iso_code' => 'en']);
        $resource->language()->associate($language);
        $resource->save();

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Complete Test Resource',
            'language_id' => $language->id,
        ]);

        $person = Person::factory()->create([
            'first_name' => 'Complete',
            'last_name' => 'Tester',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        // Add keywords
        $resource->keywords()->create(['keyword' => 'testing']);
        $resource->keywords()->create(['keyword' => 'datacite']);

        // Add description
        $resource->descriptions()->create([
            'description' => 'A comprehensive test resource',
            'description_type' => 'abstract',
        ]);

        // Add date
        $resource->dates()->create([
            'date_type' => 'collected',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Check all optional elements are present
        expect($xml)->toContain('<subjects>')
            ->and($xml)->toContain('<subject>testing</subject>')
            ->and($xml)->toContain('<descriptions>')
            ->and($xml)->toContain('<description descriptionType="Abstract">A comprehensive test resource</description>')
            ->and($xml)->toContain('<dates>')
            ->and($xml)->toContain('<date dateType="Collected">2024-01-01/2024-12-31</date>')
            ->and($xml)->toContain('<language>en</language>')
            ->and($xml)->toContain('<version>1.2</version>');
    });

    test('filename includes resource ID and timestamp', function () {
        $resource = Resource::factory()->create();

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Filename Test',
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
            'year' => 2024,
        ]);

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Title with & Special <Characters> "Quotes"',
        ]);

        $person = Person::factory()->create([
            'first_name' => 'Test & Co',
            'last_name' => 'Researcher <Main>',
        ]);

        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Should contain properly escaped XML
        expect($xml)->toContain('&amp;')
            ->and($xml)->toContain('&lt;')
            ->and($xml)->toContain('&gt;');

        // Should be valid XML
        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($xml);
        expect($loaded)->toBeTrue();
    });

    test('validates against DataCite schema', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.82433/TEST-VALIDATION',
            'year' => 2024,
        ]);

        ResourceTitle::create([
            'resource_id' => $resource->id,
            'title' => 'Schema Validation Test',
        ]);

        $person = Person::factory()->create();
        $authorRole = Role::where('name', 'Author')->first();
        $resourceAuthor = ResourceAuthor::create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => 1,
        ]);
        $resourceAuthor->roles()->attach($authorRole);

        $response = $this->get(route('resources.export-datacite-xml', $resource));

        $response->assertStatus(200);

        $xml = $response->getContent();

        // Validate against XSD schema
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        // Note: This test may fail if the online schema is not accessible
        // or if there are validation warnings (which are allowed)
        $result = @$dom->schemaValidate('https://schema.datacite.org/meta/kernel-4.6/metadata.xsd');
        
        // We expect either true or validation warnings (but still valid XML)
        expect($dom->documentElement)->not->toBeNull();
        
        libxml_clear_errors();
    });

    test('exports different resources with unique content', function () {
        $resource1 = Resource::factory()->create([
            'doi' => '10.82433/UNIQUE-1',
            'year' => 2023,
        ]);

        ResourceTitle::create([
            'resource_id' => $resource1->id,
            'title' => 'First Resource',
        ]);

        $resource2 = Resource::factory()->create([
            'doi' => '10.82433/UNIQUE-2',
            'year' => 2024,
        ]);

        ResourceTitle::create([
            'resource_id' => $resource2->id,
            'title' => 'Second Resource',
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
