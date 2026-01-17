<?php

use App\Models\IgsnMetadata;
use App\Models\Resource;
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
        $response->assertHeader('Content-Type', 'application/json');

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

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'ICDP5068EH50001',
            'publication_year' => 2026,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('igsn-ICDP5068EH50001.json');
    });

    it('generates fallback filename when IGSN is null', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => null,
            'publication_year' => 2026,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/igsns/{$resource->id}/export/json");

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain("igsn-resource-{$resource->id}.json");
    });
});
