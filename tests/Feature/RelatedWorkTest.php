<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for Related Work functionality
 * Tests database operations for related identifiers
 */
class RelatedWorkTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->resource = Resource::factory()->create();
    }

    /**
     * Test creating related identifiers for a resource
     */
    public function test_can_create_related_identifiers(): void
    {
        $relatedId = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_related_identifiers', [
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        $this->assertInstanceOf(RelatedIdentifier::class, $relatedId);
    }

    /**
     * Test updating related identifiers
     */
    public function test_can_update_related_identifiers(): void
    {
        $relatedId = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/old',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        // Delete old and create new (simulating update)
        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/new',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementedBy',
            'position' => 0,
        ]);

        $this->assertDatabaseMissing('resource_related_identifiers', [
            'identifier' => '10.1111/old',
        ]);

        $this->assertDatabaseHas('resource_related_identifiers', [
            'identifier' => '10.2222/new',
        ]);
    }

    /**
     * Test deleting all related identifiers
     */
    public function test_can_delete_all_related_identifiers(): void
    {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/to.remove',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        $this->assertDatabaseMissing('resource_related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);
    }

    /**
     * Test related identifiers maintain correct positions
     */
    public function test_maintains_positions(): void
    {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/first',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 1,
        ]);

        $this->assertDatabaseHas('resource_related_identifiers', [
            'identifier' => '10.1111/first',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_related_identifiers', [
            'identifier' => '10.2222/second',
            'position' => 1,
        ]);
    }

    /**
     * Test allows same identifier with different relation types
     */
    public function test_allows_same_identifier_with_different_relation_types(): void
    {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementTo',
            'position' => 1,
        ]);

        $this->assertEquals(2, RelatedIdentifier::where('resource_id', $this->resource->id)->count());
    }

    /**
     * Test relationship between Resource and RelatedIdentifier models
     */
    public function test_resource_has_many_related_identifiers(): void
    {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/first',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementTo',
            'position' => 1,
        ]);

        // Refresh the model to load relationships
        $this->resource->refresh();
        
        $relatedIdentifiers = $this->resource->relatedIdentifiers()->get();
        $this->assertCount(2, $relatedIdentifiers);
        $this->assertEquals('10.1111/first', $relatedIdentifiers[0]->identifier);
        $this->assertEquals('10.2222/second', $relatedIdentifiers[1]->identifier);
    }

    /**
     * Test deleting a resource cascades to related identifiers
     */
    public function test_deleting_resource_cascades_to_related_identifiers(): void
    {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);

        $this->resource->delete();

        $this->assertDatabaseMissing('resource_related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);
    }
}
