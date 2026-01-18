<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
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

/**
 * Helper function to create a valid resource with required DataCite fields.
 */
function createValidResource(array $attributes = []): Resource
{
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::factory()->create(array_merge([
        'doi' => '10.5880/test.2026.' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
        'publication_year' => 2026,
    ], $attributes));

    // Add required title
    $resource->titles()->create([
        'value' => 'Test Dataset Title',
        'title_type_id' => $mainTitleType->id,
        'position' => 1,
    ]);

    // Add required creator (using polymorphic relation)
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

    return $resource;
}

describe('Resource JSON Export with Schema Validation', function () {
    it('exports valid resource as DataCite JSON successfully', function () {
        $user = User::factory()->create();
        $resource = createValidResource(['doi' => '10.5880/test.2026.001']);

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('returns 422 with validation errors for invalid resource data', function () {
        $user = User::factory()->create();

        // Create resource without required title
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.2026.002',
            'publication_year' => 2026,
        ]);

        // Don't add any title - this will make the export invalid

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

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

    it('includes schema version in error response', function () {
        $user = User::factory()->create();

        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.2026.003',
            'publication_year' => 2026,
        ]);

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertStatus(422);
        $response->assertJsonPath('schema_version', '4.6');
    });

    it('includes multiple validation errors in response', function () {
        $user = User::factory()->create();

        // Create a minimal invalid resource
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.2026.004',
            'publication_year' => 2026,
        ]);

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertStatus(422);

        $json = $response->json();
        expect($json['errors'])->toBeArray();
        // Should have at least one error (missing required fields)
        expect(count($json['errors']))->toBeGreaterThanOrEqual(1);
    });

    it('requires authentication for export', function () {
        $resource = Resource::factory()->create();

        $response = $this->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertRedirect('/login');
    });

    it('returns 404 for non-existent resource', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/resources/99999/export-datacite-json');

        $response->assertNotFound();
    });

    it('generates correct filename with timestamp', function () {
        $user = User::factory()->create();
        $resource = createValidResource(['doi' => '10.5880/test.2026.005']);

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertOk();

        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain("resource-{$resource->id}");
        expect($contentDisposition)->toContain('-datacite.json');
    });

    it('validates DataCite JSON structure in successful response', function () {
        $user = User::factory()->create();
        $resource = createValidResource(['doi' => '10.5880/test.2026.006']);

        $response = $this->actingAs($user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        $response->assertOk();

        $json = $response->json();

        // Verify DataCite wrapper structure
        expect($json)->toHaveKey('data');
        expect($json['data'])->toHaveKey('type');
        expect($json['data']['type'])->toBe('dois');
        expect($json['data'])->toHaveKey('attributes');

        // Verify required attributes
        $attributes = $json['data']['attributes'];
        expect($attributes)->toHaveKey('titles');
        expect($attributes)->toHaveKey('creators');
        expect($attributes)->toHaveKey('publisher');
        expect($attributes)->toHaveKey('publicationYear');
        expect($attributes)->toHaveKey('types');
    });
});
