<?php

use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed required data for DataCite JSON export
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'LanguageSeeder']);
    $this->artisan('db:seed', ['--class' => 'PublisherSeeder']);
});

describe('IGSN JSON Export', function () {
    it('exports IGSN as DataCite JSON', function () {
        $user = User::factory()->create();

        // Get the Physical Object resource type
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        expect($physicalObjectType)->not->toBeNull();

        // Get main title type
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        expect($mainTitleType)->not->toBeNull();

        // Create a resource with IGSN metadata
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-TEST-001',
            'publication_year' => 2026,
        ]);

        // Add a title
        $resource->titles()->create([
            'value' => 'Test IGSN Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        // Add required creator (explicit per DataCite schema)
        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'Jane',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        // Create IGSN metadata
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'material' => 'Granite',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('application/json');

        // Verify it's a download
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment');
        expect($contentDisposition)->toContain('igsn-IGSN-TEST-001.json');

        // Parse the JSON content
        $json = json_decode($response->streamedContent(), true);

        // Verify DataCite structure
        expect($json)->toHaveKey('data');
        expect($json['data'])->toHaveKey('type');
        expect($json['data']['type'])->toBe('dois');
        expect($json['data'])->toHaveKey('attributes');

        // Verify some attributes
        $attributes = $json['data']['attributes'];
        expect($attributes)->toHaveKey('titles');
        expect($attributes['titles'][0]['title'])->toBe('Test IGSN Sample');
    });

    it('returns 404 for non-IGSN resources', function () {
        $user = User::factory()->create();

        // Create a regular resource without IGSN metadata
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.2026.001',
            'publication_year' => 2026,
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertNotFound();
    });

    it('returns 404 for non-existent resources', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/igsns/99999/export/json');

        $response->assertNotFound();
    });

    it('requires authentication', function () {
        // Get the Physical Object resource type
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-TEST-002',
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        // Without authentication
        $response = $this->get("/igsns/{$resource->id}/export/json");

        $response->assertRedirect('/login');
    });

    it('generates correct filename from IGSN', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'ICDP5068EH50001',
            'publication_year' => 2026,
        ]);

        $resource->titles()->create([
            'value' => 'Test IGSN Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'John',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toBeString();
        expect(str_contains($contentDisposition, 'igsn-ICDP5068EH50001.json'))->toBeTrue();
    });

    it('generates fallback filename when IGSN is null', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => null,
            'publication_year' => 2026,
        ]);

        $resource->titles()->create([
            'value' => 'Test IGSN Sample Without DOI',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'John',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        // Export without DOI should succeed - DOI is only required for registration, not export
        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toBeString();
        expect(str_contains($contentDisposition, "igsn-resource-{$resource->id}.json"))->toBeTrue();
    });
});

describe('IGSN JSON Export with Schema Validation', function () {
    it('exports valid IGSN as DataCite JSON successfully', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-VALID-001',
            'publication_year' => 2026,
        ]);

        $resource->titles()->create([
            'value' => 'Valid IGSN Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        // Add required creator (explicit per DataCite schema)
        $person = Person::factory()->create([
            'family_name' => 'Miller',
            'given_name' => 'Bob',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'material' => 'Granite',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    });

    it('returns 422 with validation errors for invalid IGSN data', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        // Create IGSN without required title
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-INVALID-001',
            'publication_year' => 2026,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'JSON export validation failed against DataCite Schema.',
        ]);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                '*' => ['path', 'message', 'keyword', 'context'],
            ],
            'schema_version',
        ]);
    });

    it('includes schema version 4.6 in error response', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-INVALID-002',
            'publication_year' => 2026,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertStatus(422);
        $response->assertJsonPath('schema_version', '4.6');
    });

    it('includes human-readable error messages', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-INVALID-003',
            'publication_year' => 2026,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertStatus(422);

        $json = $response->json();
        expect($json['errors'])->toBeArray();
        expect($json['errors'][0]['message'])->toBeString();
        // Message should contain both human-readable text and path
        expect($json['errors'][0]['message'])->toContain('(Path:');
    });

    it('validates PhysicalObject as valid resourceTypeGeneral', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-PHYSOBJ-001',
            'publication_year' => 2026,
        ]);

        $resource->titles()->create([
            'value' => 'Physical Object Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Sediment',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();

        // Verify the JSON contains PhysicalObject type
        $json = json_decode($response->streamedContent(), true);
        $attributes = $json['data']['attributes'];
        expect($attributes['types']['resourceTypeGeneral'])->toBe('PhysicalObject');
    });
});

describe('IGSN Collection Date Export', function () {
    it('exports collection date range in DataCite JSON format', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        $collectedDateType = \App\Models\DateType::where('slug', 'Collected')->first();

        // Create resource
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-DATE-TEST-001',
            'publication_year' => 2024,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample with Collection Date',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        // Add creator
        $person = \App\Models\Person::factory()->create();
        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        // Add IGSN metadata
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Core',
            'material' => 'Sediment',
            'upload_status' => 'pending',
        ]);

        // Add collection date range (like from CSV)
        \App\Models\ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'start_date' => '2024-01-15',
            'end_date' => '2024-06-30',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);
        $attributes = $json['data']['attributes'];

        // Verify dates are exported correctly
        expect($attributes)->toHaveKey('dates');
        expect($attributes['dates'])->toHaveCount(1);
        expect($attributes['dates'][0]['date'])->toBe('2024-01-15/2024-06-30');
        expect($attributes['dates'][0]['dateType'])->toBe('Collected');
    });

    it('exports collection date with year-only format in DataCite JSON', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        $collectedDateType = \App\Models\DateType::where('slug', 'Collected')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-DATE-TEST-002',
            'publication_year' => 2020,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample with Year Range',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = \App\Models\Person::factory()->create();
        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Borehole',
            'material' => 'Rock',
            'upload_status' => 'pending',
        ]);

        // Add collection date with year-only format
        \App\Models\ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'start_date' => '2020',
            'end_date' => '2024',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);

        expect($json['data']['attributes']['dates'][0]['date'])->toBe('2020/2024');
        expect($json['data']['attributes']['dates'][0]['dateType'])->toBe('Collected');
    });

    it('exports open-ended collection date (start only) in DataCite JSON', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        $collectedDateType = \App\Models\DateType::where('slug', 'Collected')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-DATE-TEST-003',
            'publication_year' => 2024,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample Open-Ended',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = \App\Models\Person::factory()->create();
        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Sample',
            'material' => 'Water',
            'upload_status' => 'pending',
        ]);

        // Add collection date with only start date (open-ended)
        \App\Models\ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'start_date' => '2024-03-15',
            'end_date' => null,
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);

        // Open-ended ranges should be exported as single date
        expect($json['data']['attributes']['dates'][0]['date'])->toBe('2024-03-15');
        expect($json['data']['attributes']['dates'][0]['dateType'])->toBe('Collected');
    });

    it('exports collection date in DataCite XML format', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        $collectedDateType = \App\Models\DateType::where('slug', 'Collected')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-DATE-TEST-004',
            'publication_year' => 2021,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample XML Export',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = \App\Models\Person::factory()->create();
        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Borehole',
            'material' => 'Sediment',
            'upload_status' => 'pending',
        ]);

        // Add collection date range
        \App\Models\ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'start_date' => '2021-04-12',
            'end_date' => '2021-05-05',
        ]);

        // Use the general resources XML export endpoint (works for all resource types including IGSNs)
        $response = $this->actingAs($user)
            ->get(route('resources.export-datacite-xml', $resource));

        $response->assertOk();

        // Get XML content (may be regular response or streamed)
        $xml = method_exists($response->baseResponse, 'streamedContent')
            ? $response->streamedContent()
            : $response->getContent();

        // Verify XML contains the date with correct format
        expect($xml)->toContain('<dates>');
        expect($xml)->toContain('dateType="Collected"');
        expect($xml)->toContain('2021-04-12/2021-05-05</date>');
    });
});

describe('IGSN Creator Export', function () {
    it('exports creator with nameType Personal in DataCite JSON', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-CREATOR-TEST-001',
            'publication_year' => 2024,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample with Creator',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        // Create person with ORCID
        $person = \App\Models\Person::factory()->create([
            'given_name' => 'Sofia',
            'family_name' => 'Garcia',
            'name_identifier' => 'https://orcid.org/0000-0001-5727-2427',
            'name_identifier_scheme' => 'ORCID',
        ]);

        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'material' => 'Granite',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);
        $creators = $json['data']['attributes']['creators'];

        expect($creators)->toHaveCount(1);
        expect($creators[0]['nameType'])->toBe('Personal');
        expect($creators[0]['givenName'])->toBe('Sofia');
        expect($creators[0]['familyName'])->toBe('Garcia');
        expect($creators[0]['name'])->toBe('Garcia, Sofia');
    });

    it('exports creator with ORCID nameIdentifier in DataCite JSON', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-CREATOR-TEST-002',
            'publication_year' => 2024,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample with ORCID',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = \App\Models\Person::factory()->create([
            'given_name' => 'Gerald',
            'family_name' => 'Gabriel',
            'name_identifier' => 'https://orcid.org/0000-0001-9404-882X',
            'name_identifier_scheme' => 'ORCID',
        ]);

        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Borehole',
            'material' => 'Sediment',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);
        $creator = $json['data']['attributes']['creators'][0];

        // Verify nameIdentifiers array structure
        expect($creator)->toHaveKey('nameIdentifiers');
        expect($creator['nameIdentifiers'])->toHaveCount(1);
        expect($creator['nameIdentifiers'][0]['nameIdentifier'])->toBe('https://orcid.org/0000-0001-9404-882X');
        expect($creator['nameIdentifiers'][0]['nameIdentifierScheme'])->toBe('ORCID');
        expect($creator['nameIdentifiers'][0]['schemeUri'])->toBe('https://orcid.org');
    });

    it('exports creator in DataCite XML with correct structure', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-CREATOR-TEST-003',
            'publication_year' => 2024,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample XML Creator',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = \App\Models\Person::factory()->create([
            'given_name' => 'John',
            'family_name' => 'Doe',
            'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
        ]);

        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Core',
            'material' => 'Rock',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get(route('resources.export-datacite-xml', $resource));

        $response->assertOk();
        $xml = method_exists($response->baseResponse, 'streamedContent')
            ? $response->streamedContent()
            : $response->getContent();

        // Verify XML structure for creator
        expect($xml)->toContain('<creators>');
        expect($xml)->toContain('nameType="Personal"');
        expect($xml)->toContain('<givenName>John</givenName>');
        expect($xml)->toContain('<familyName>Doe</familyName>');
        expect($xml)->toContain('nameIdentifierScheme="ORCID"');
        expect($xml)->toContain('schemeURI="https://orcid.org"');
    });

    it('exports all three name fields (name, givenName, familyName) in JSON', function () {
        $user = User::factory()->create();
        $resourceType = ResourceType::where('name', 'Physical Object')->first();
        $titleType = TitleType::where('name', 'Main Title')->first();

        $resource = Resource::create([
            'doi' => '10.58052/IGSN.NAME-TEST',
            'publication_year' => now()->year,
            'resource_type_id' => $resourceType->id,
        ]);

        $resource->titles()->create([
            'value' => 'Test Name Fields',
            'title_type_id' => $titleType->id,
            'position' => 1,
        ]);

        // Create a person with both given and family name
        $person = Person::create([
            'given_name' => 'Maria',
            'family_name' => 'Garcia',
        ]);

        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Core',
            'material' => 'Rock',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get(route('resources.export-datacite-json', $resource));

        $response->assertOk();
        $json = json_decode($response->getContent(), true);
        $creator = $json['data']['attributes']['creators'][0];

        // All three fields should be present
        expect($creator)->toHaveKey('name');
        expect($creator)->toHaveKey('givenName');
        expect($creator)->toHaveKey('familyName');

        // Verify values
        expect($creator['name'])->toBe('Garcia, Maria');
        expect($creator['givenName'])->toBe('Maria');
        expect($creator['familyName'])->toBe('Garcia');
        expect($creator['nameType'])->toBe('Personal');
    });

    it('exports name field correctly when only familyName is provided', function () {
        $user = User::factory()->create();
        $resourceType = ResourceType::where('name', 'Physical Object')->first();
        $titleType = TitleType::where('name', 'Main Title')->first();

        $resource = Resource::create([
            'doi' => '10.58052/IGSN.FAMILY-ONLY',
            'publication_year' => now()->year,
            'resource_type_id' => $resourceType->id,
        ]);

        $resource->titles()->create([
            'value' => 'Test Family Only',
            'title_type_id' => $titleType->id,
            'position' => 1,
        ]);

        // Create a person with only family name (like "Darwin")
        $person = Person::create([
            'given_name' => null,
            'family_name' => 'Darwin',
        ]);

        \App\Models\ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Core',
            'material' => 'Rock',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get(route('resources.export-datacite-json', $resource));

        $response->assertOk();
        $json = json_decode($response->getContent(), true);
        $creator = $json['data']['attributes']['creators'][0];

        // name should be constructed from familyName only
        expect($creator['name'])->toBe('Darwin');
        expect($creator['familyName'])->toBe('Darwin');
        expect($creator)->not->toHaveKey('givenName'); // Not present when null
    });});