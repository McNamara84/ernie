<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceFundingReference;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for Resource Controller Funding Reference operations
 * Tests saving and loading funding references via the ResourceController
 */
class ResourceControllerFundingReferenceTest extends TestCase
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
                    'description' => 'This is a test abstract.',
                ],
            ],
        ], $overrides);
    }

    /**
     * Test saving funding references when creating a new resource
     */
    public function test_can_save_funding_references_when_creating_resource(): void
    {
        $payload = $this->getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'European Research Council',
                    'funderIdentifier' => 'https://doi.org/10.13039/501100000780',
                    'funderIdentifierType' => 'Crossref Funder ID',
                    'awardNumber' => 'ERC-2021-STG-123456',
                    'awardUri' => 'https://cordis.europa.eu/project/id/123456',
                    'awardTitle' => 'Innovative AI Research',
                ],
                [
                    'funderName' => 'Deutsche Forschungsgemeinschaft',
                    'funderIdentifier' => 'https://ror.org/018mejw64',
                    'funderIdentifierType' => 'ROR',
                    'awardNumber' => 'DFG-2024-789',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('resource_funding_references', [
            'funder_name' => 'European Research Council',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'funder_identifier_type' => 'Crossref Funder ID',
            'award_number' => 'ERC-2021-STG-123456',
            'award_uri' => 'https://cordis.europa.eu/project/id/123456',
            'award_title' => 'Innovative AI Research',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type' => 'ROR',
            'award_number' => 'DFG-2024-789',
            'position' => 1,
        ]);

        // Check that the resource has 2 funding references
        $resource = Resource::latest()->first();
        $this->assertCount(2, $resource->fundingReferences);
    }

    /**
     * Test updating funding references on existing resource
     */
    public function test_can_update_funding_references_on_existing_resource(): void
    {
        // Create resource with one funding reference
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->resourceType->id,
        ]);

        ResourceFundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Old Funder',
            'funder_identifier' => 'https://ror.org/old',
            'funder_identifier_type' => 'ROR',
            'position' => 0,
        ]);

        // Update with different funding references
        $payload = $this->getValidPayload([
            'resourceId' => $resource->id,
            'fundingReferences' => [
                [
                    'funderName' => 'New Funder 1',
                    'funderIdentifier' => 'https://doi.org/10.13039/new1',
                    'funderIdentifierType' => 'Crossref Funder ID',
                    'awardNumber' => 'NEW-001',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => 'New Funder 2',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(200);

        // Old funding reference should be deleted
        $this->assertDatabaseMissing('resource_funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'Old Funder',
        ]);

        // New funding references should exist
        $this->assertDatabaseHas('resource_funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'New Funder 1',
            'funder_identifier' => 'https://doi.org/10.13039/new1',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'New Funder 2',
            'position' => 1,
        ]);

        $this->assertCount(2, $resource->fresh()->fundingReferences);
    }

    /**
     * Test saving only minimal required data (funderName)
     */
    public function test_can_save_funding_reference_with_only_funder_name(): void
    {
        $payload = $this->getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'Minimal Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('resource_funding_references', [
            'funder_name' => 'Minimal Funder',
            'funder_identifier' => null,
            'funder_identifier_type' => null,
            'award_number' => null,
            'award_uri' => null,
            'award_title' => null,
        ]);
    }

    /**
     * Test that empty funder names are not saved
     */
    public function test_does_not_save_funding_reference_with_empty_funder_name(): void
    {
        $payload = $this->getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => '',
                    'funderIdentifier' => 'https://ror.org/example',
                    'funderIdentifierType' => 'ROR',
                    'awardNumber' => 'TEST-123',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => '   ',  // Whitespace only
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => 'Valid Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
    }

    /**
     * Test validation: funder identifier type must be in allowed list
     */
    public function test_validates_funder_identifier_type(): void
    {
        $payload = [
            'year' => 2024,
            'resourceType' => (string) $this->resourceType->id,
            'language' => 'en',
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'main-title'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'fundingReferences' => [
                [
                    'funderName' => 'Test Funder',
                    'funderIdentifier' => 'https://example.org/invalid',
                    'funderIdentifierType' => 'InvalidType',  // Not in allowed list
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);  // Unprocessable Entity
        $response->assertJsonValidationErrors(['fundingReferences.0.funderIdentifierType']);
    }

    /**
     * Test validation: award URI must be valid URL
     */
    public function test_validates_award_uri_format(): void
    {
        $payload = [
            'year' => 2024,
            'resourceType' => (string) $this->resourceType->id,
            'language' => 'en',
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'main-title'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'fundingReferences' => [
                [
                    'funderName' => 'Test Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => 'TEST-123',
                    'awardUri' => 'not-a-valid-url',  // Invalid URL
                    'awardTitle' => '',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fundingReferences.0.awardUri']);
    }

    /**
     * Test that positions are correctly saved
     */
    public function test_preserves_funding_reference_positions(): void
    {
        $payload = $this->getValidPayload([
            'fundingReferences' => [
                ['funderName' => 'First', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
                ['funderName' => 'Second', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
                ['funderName' => 'Third', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        $fundingRefs = $resource->fundingReferences()->orderBy('position')->get();

        $this->assertEquals('First', $fundingRefs[0]->funder_name);
        $this->assertEquals(0, $fundingRefs[0]->position);

        $this->assertEquals('Second', $fundingRefs[1]->funder_name);
        $this->assertEquals(1, $fundingRefs[1]->position);

        $this->assertEquals('Third', $fundingRefs[2]->funder_name);
        $this->assertEquals(2, $fundingRefs[2]->position);
    }
}
