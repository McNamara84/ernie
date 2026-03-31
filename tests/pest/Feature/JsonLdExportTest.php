<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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

describe('Resource JSON-LD Export', function () {
    it('exports resource as DataCite Linked Data JSON-LD', function () {
        $user = User::factory()->create();
        $resource = createJsonLdTestResource();

        $response = $this->actingAs($user)
            ->get(route('resources.export-jsonld', $resource));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/ld+json');

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment');
        expect($contentDisposition)->toContain('.jsonld');

        $json = json_decode($response->content(), true);
        expect($json)->toHaveKey('@context');
        expect($json)->toHaveKey('titles');
        expect($json)->toHaveKey('publicationYear');
    });

    it('includes @id when resource has DOI', function () {
        $user = User::factory()->create();
        $resource = createJsonLdTestResource('10.5880/test.jsonld.001');

        $response = $this->actingAs($user)
            ->get(route('resources.export-jsonld', $resource));

        $json = json_decode($response->content(), true);
        expect($json['@id'])->toBe('https://doi.org/10.5880/test.jsonld.001');
    });

    it('requires authentication', function () {
        $resource = createJsonLdTestResource();

        $response = $this->get(route('resources.export-jsonld', $resource));

        $response->assertRedirect('/login');
    });

    it('returns 404 for non-existent resource', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/resources/99999/export-jsonld');

        $response->assertNotFound();
    });
});

describe('IGSN JSON-LD Export', function () {
    it('exports IGSN as DataCite Linked Data JSON-LD', function () {
        $user = User::factory()->create();
        $resource = createIgsnJsonLdTestResource();

        $response = $this->actingAs($user)
            ->get(route('igsns.export.jsonld', $resource));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/ld+json');

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment');
        expect($contentDisposition)->toContain('.jsonld');

        $json = json_decode($response->streamedContent(), true);
        expect($json)->toHaveKey('@context');
        expect($json)->toHaveKey('titles');
    });

    it('returns 404 for non-IGSN resources', function () {
        $user = User::factory()->create();
        $resource = createJsonLdTestResource();

        $response = $this->actingAs($user)
            ->get(route('igsns.export.jsonld', $resource));

        $response->assertNotFound();
    });

    it('requires authentication', function () {
        $resource = createIgsnJsonLdTestResource();

        $response = $this->get(route('igsns.export.jsonld', $resource));

        $response->assertRedirect('/login');
    });
});

describe('Landing Page JSON-LD Export', function () {
    it('exports published landing page as DataCite Linked Data JSON-LD', function () {
        $resource = createJsonLdTestResource('10.5880/test.jsonld.lp');

        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/test.jsonld.lp',
            'slug' => 'test-jsonld-dataset',
            'template' => 'default_gfz',
        ]);

        $response = $this->get('/10.5880/test.jsonld.lp/test-jsonld-dataset/jsonld');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/ld+json');

        $json = json_decode($response->content(), true);
        expect($json)->toHaveKey('@context');
        expect($json['@id'])->toBe('https://doi.org/10.5880/test.jsonld.lp');
    });

    it('returns 404 for draft landing pages', function () {
        $resource = createJsonLdTestResource('10.5880/test.jsonld.draft');

        LandingPage::factory()->draft()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/test.jsonld.draft',
            'slug' => 'draft-jsonld-dataset',
            'template' => 'default_gfz',
        ]);

        $response = $this->get('/10.5880/test.jsonld.draft/draft-jsonld-dataset/jsonld');

        $response->assertNotFound();
    });

    it('returns 404 for non-existent landing pages', function () {
        $response = $this->get('/10.5880/nonexistent/unknown-slug/jsonld');

        $response->assertNotFound();
    });

    it('does not require authentication for public landing pages', function () {
        $resource = createJsonLdTestResource('10.5880/test.public.jsonld');

        LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/test.public.jsonld',
            'slug' => 'public-jsonld-dataset',
            'template' => 'default_gfz',
        ]);

        $response = $this->get('/10.5880/test.public.jsonld/public-jsonld-dataset/jsonld');

        $response->assertOk();
    });

    it('includes proper content-disposition filename', function () {
        $resource = createJsonLdTestResource('10.5880/test.jsonld.fn');

        LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/test.jsonld.fn',
            'slug' => 'my-dataset',
            'template' => 'default_gfz',
        ]);

        $response = $this->get('/10.5880/test.jsonld.fn/my-dataset/jsonld');

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('my-dataset-datacite-ld.jsonld');
    });
});

// --- Helpers ---

function createJsonLdTestResource(?string $doi = '10.5880/test.jsonld.001'): Resource
{
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::factory()->create([
        'doi' => $doi,
        'publication_year' => 2025,
    ]);

    $resource->titles()->create([
        'value' => 'JSON-LD Test Resource',
        'title_type_id' => $mainTitleType?->id,
    ]);

    $person = Person::factory()->create([
        'family_name' => 'Test',
        'given_name' => 'Author',
    ]);

    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);

    return $resource;
}

function createIgsnJsonLdTestResource(): Resource
{
    $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
        'doi' => 'IGSN-JSONLD-TEST-001',
        'publication_year' => 2025,
    ]);

    $resource->titles()->create([
        'value' => 'IGSN JSON-LD Test Sample',
        'title_type_id' => $mainTitleType?->id,
    ]);

    $person = Person::factory()->create([
        'family_name' => 'Sample',
        'given_name' => 'Collector',
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

    return $resource;
}
