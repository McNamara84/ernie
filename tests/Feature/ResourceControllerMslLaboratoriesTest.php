<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for MSL Laboratories in Resource Controller
 * Tests saving and loading MSL laboratories via the ResourceController
 */
class ResourceControllerMslLaboratoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ResourceType $resourceType;

    private Language $language;

    private License $license;

    private TitleType $titleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->resourceType = ResourceType::create([
            'name' => 'Dataset',
            'slug' => 'dataset',
        ]);
        $this->language = Language::create([
            'code' => 'en',
            'name' => 'English',
            'active' => true,
            'elmo_active' => true,
        ]);
        $this->license = License::create([
            'identifier' => 'cc-by-4',
            'name' => 'Creative Commons Attribution 4.0',
        ]);
        $this->titleType = TitleType::create([
            'name' => 'Main Title',
            'slug' => 'main-title',
        ]);
    }

    /**
     * Helper to get valid resource payload
     */
    private function getValidPayload(array $overrides = []): array
    {
        return array_merge([
            'year' => 2024,
            'resourceType' => (string) $this->resourceType->id,
            'language' => 'en',
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'main-title'],
            ],
            'licenses' => ['cc-by-4'],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'descriptions' => [
                [
                    'descriptionType' => 'Abstract',
                    'description' => 'Test abstract',
                ],
            ],
            'dates' => [],
            'freeKeywords' => [],
            'gcmdKeywords' => [],
            'spatialTemporalCoverages' => [],
            'relatedIdentifiers' => [],
            'fundingReferences' => [],
            'contributors' => [],
            'mslLaboratories' => [],
        ], $overrides);
    }

    public function test_can_save_resource_with_msl_laboratories(): void
    {
        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'abc123def456',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Check institution was created
        $this->assertDatabaseHas('institutions', [
            'identifier' => 'abc123def456',
            'identifier_type' => 'labid',
            'name' => 'Test Laboratory',
        ]);

        // Check role was created/assigned
        $this->assertDatabaseHas('roles', [
            'slug' => 'hosting-institution',
            'name' => 'Hosting Institution',
        ]);

        // Get the resource
        $resource = Resource::latest()->first();
        $this->assertNotNull($resource);

        // Check ResourceAuthor link
        $this->assertDatabaseHas('resource_authors', [
            'resource_id' => $resource->id,
            'authorable_type' => Institution::class,
            'position' => 0,
        ]);

        // Check affiliation
        $this->assertDatabaseHas('affiliations', [
            'value' => 'Test University',
            'ror_id' => 'https://ror.org/test',
        ]);
    }

    public function test_can_save_multiple_msl_laboratories(): void
    {
        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'lab1',
                    'name' => 'Laboratory 1',
                    'affiliation_name' => 'University 1',
                    'affiliation_ror' => 'https://ror.org/uni1',
                ],
                [
                    'identifier' => 'lab2',
                    'name' => 'Laboratory 2',
                    'affiliation_name' => 'University 2',
                    'affiliation_ror' => 'https://ror.org/uni2',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Check both institutions were created
        $this->assertDatabaseHas('institutions', [
            'identifier' => 'lab1',
            'identifier_type' => 'labid',
        ]);

        $this->assertDatabaseHas('institutions', [
            'identifier' => 'lab2',
            'identifier_type' => 'labid',
        ]);

        $resource = Resource::latest()->first();

        // Check both have correct positions
        $lab1 = Institution::where('identifier', 'lab1')->first();
        $lab2 = Institution::where('identifier', 'lab2')->first();

        $this->assertDatabaseHas('resource_authors', [
            'resource_id' => $resource->id,
            'authorable_id' => $lab1->id,
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_authors', [
            'resource_id' => $resource->id,
            'authorable_id' => $lab2->id,
            'position' => 1,
        ]);
    }

    public function test_reuses_existing_msl_laboratory(): void
    {
        // Create existing laboratory
        $existingLab = Institution::create([
            'identifier' => 'existing123',
            'identifier_type' => 'labid',
            'name' => 'Existing Lab',
        ]);

        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'existing123',
                    'name' => 'Updated Lab Name',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Should still only have one institution with this ID
        $this->assertEquals(1, Institution::where('identifier', 'existing123')->count());

        // Name should be updated
        $existingLab->refresh();
        $this->assertEquals('Updated Lab Name', $existingLab->name);
    }

    public function test_msl_laboratories_without_affiliation_ror(): void
    {
        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'lab123',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Check affiliation was created without ROR ID
        $this->assertDatabaseHas('affiliations', [
            'value' => 'Test University',
            'ror_id' => null,
        ]);
    }

    public function test_can_update_resource_with_new_msl_laboratories(): void
    {
        // Create initial resource
        $initialPayload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'lab1',
                    'name' => 'Laboratory 1',
                    'affiliation_name' => 'University 1',
                    'affiliation_ror' => 'https://ror.org/uni1',
                ],
            ],
        ]);

        $response1 = $this->actingAs($this->user)
            ->postJson('/curation/resources', $initialPayload);

        $response1->assertStatus(201);
        $resource = Resource::latest()->first();

        // Update with different laboratories
        $updatePayload = $this->getValidPayload([
            'resourceId' => $resource->id,
            'mslLaboratories' => [
                [
                    'identifier' => 'lab2',
                    'name' => 'Laboratory 2',
                    'affiliation_name' => 'University 2',
                    'affiliation_ror' => 'https://ror.org/uni2',
                ],
            ],
        ]);

        $response2 = $this->actingAs($this->user)
            ->postJson('/curation/resources', $updatePayload);

        $response2->assertStatus(200);

        // Refresh resource to reload relationships
        $resource->refresh();

        // Old lab should no longer be linked to this resource
        $lab1 = Institution::where('identifier', 'lab1')->first();
        $this->assertEquals(0, $resource->contributors()
            ->where('authorable_id', $lab1->id)
            ->where('authorable_type', Institution::class)
            ->count());

        // New lab should be linked
        $lab2 = Institution::where('identifier', 'lab2')->first();
        $this->assertEquals(1, $resource->contributors()
            ->where('authorable_id', $lab2->id)
            ->where('authorable_type', Institution::class)
            ->count());
    }

    public function test_msl_laboratories_are_separated_from_regular_contributors(): void
    {
        $payload = $this->getValidPayload([
            'contributors' => [
                [
                    'type' => 'institution',
                    'institutionName' => 'Regular Institution',
                    'roles' => ['Data Collector'],
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'mslLaboratories' => [
                [
                    'identifier' => 'lab123',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Check regular institution (no identifier_type)
        $this->assertDatabaseHas('institutions', [
            'name' => 'Regular Institution',
            'identifier_type' => null,
        ]);

        // Check MSL lab (with labid identifier_type)
        $this->assertDatabaseHas('institutions', [
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        // Check roles are different
        $resource = Resource::latest()->first();
        $regularInst = Institution::where('name', 'Regular Institution')->first();
        $lab = Institution::where('identifier', 'lab123')->first();

        $regularContributor = $resource->contributors()->where('authorable_id', $regularInst->id)->first();
        $labContributor = $resource->contributors()->where('authorable_id', $lab->id)->first();

        // Regular contributor should have different role
        $this->assertNotEquals(
            $regularContributor->roles->pluck('slug')->toArray(),
            $labContributor->roles->pluck('slug')->toArray()
        );

        // Lab should have hosting-institution role
        $this->assertTrue($labContributor->roles->contains('slug', 'hosting-institution'));
    }

    public function test_can_load_resource_with_msl_laboratories(): void
    {
        // Create resource with MSL labs
        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    'identifier' => 'lab123',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        // Verify the data is in the database
        $resource = Resource::latest()->first();
        $this->assertNotNull($resource);

        // Load resource with contributors
        $resource->load([
            'contributors' => function ($query) {
                $query->with(['authorable', 'roles', 'affiliations']);
            },
        ]);

        // Check MSL lab is attached as contributor
        $mslLab = $resource->contributors->first(function ($contributor) {
            $authorable = $contributor->authorable;
            return $authorable instanceof Institution && $authorable->identifier_type === 'labid';
        });

        $this->assertNotNull($mslLab);
        $this->assertEquals('lab123', $mslLab->authorable->identifier);
        $this->assertEquals('Test Laboratory', $mslLab->authorable->name);
    }

    public function test_msl_laboratories_are_separated_in_database(): void
    {
        // Create resource with both contributors and MSL labs
        $payload = $this->getValidPayload([
            'contributors' => [
                [
                    'type' => 'institution',
                    'institutionName' => 'Regular Institution',
                    'roles' => ['Data Collector'],
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'mslLaboratories' => [
                [
                    'identifier' => 'lab123',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        // Verify both are in database with different identifier types
        $this->assertDatabaseHas('institutions', [
            'name' => 'Regular Institution',
            'identifier_type' => null,
        ]);

        $this->assertDatabaseHas('institutions', [
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        // Verify MSL lab has hosting-institution role
        $resource = Resource::latest()->first();
        $lab = Institution::where('identifier', 'lab123')->first();
        $labContributor = $resource->contributors()->where('authorable_id', $lab->id)->first();

        $this->assertTrue($labContributor->roles->contains('slug', 'hosting-institution'));
    }

    public function test_skips_msl_laboratories_with_missing_required_fields(): void
    {
        // prepareForValidation() should skip entries with missing identifier or name
        $payload = $this->getValidPayload([
            'mslLaboratories' => [
                [
                    // Missing identifier - should be skipped
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
                [
                    // Valid entry
                    'identifier' => 'valid123',
                    'name' => 'Valid Lab',
                    'affiliation_name' => 'Valid University',
                    'affiliation_ror' => 'https://ror.org/valid',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);

        // Only the valid entry should be saved
        $this->assertDatabaseHas('institutions', [
            'identifier' => 'valid123',
            'identifier_type' => 'labid',
        ]);

        // The invalid entry should NOT be saved
        $this->assertDatabaseMissing('institutions', [
            'name' => 'Test Laboratory',
            'identifier_type' => 'labid',
        ]);
    }

    public function test_empty_msl_laboratories_array_is_valid(): void
    {
        $payload = $this->getValidPayload([
            'mslLaboratories' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
    }

    public function test_missing_msl_laboratories_field_is_valid(): void
    {
        $payload = $this->getValidPayload();
        unset($payload['mslLaboratories']);

        $response = $this->actingAs($this->user)
            ->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
    }
}
