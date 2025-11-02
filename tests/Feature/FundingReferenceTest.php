<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\ResourceFundingReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for Funding Reference functionality
 * Tests database operations for funding references
 */
class FundingReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = Resource::factory()->create();
    }

    /**
     * Test creating funding references for a resource
     */
    public function test_can_create_funding_reference(): void
    {
        $fundingRef = ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft (DFG)',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type' => 'ROR',
            'award_number' => 'ERC-2021-STG-101234567',
            'award_uri' => 'https://cordis.europa.eu/project/id/101234567',
            'award_title' => 'Innovative Research in AI Systems',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'resource_id' => $this->resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft (DFG)',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type' => 'ROR',
            'award_number' => 'ERC-2021-STG-101234567',
            'award_uri' => 'https://cordis.europa.eu/project/id/101234567',
            'award_title' => 'Innovative Research in AI Systems',
            'position' => 0,
        ]);

        $this->assertInstanceOf(ResourceFundingReference::class, $fundingRef);
    }

    /**
     * Test creating funding reference with minimal data (only required fields)
     */
    public function test_can_create_funding_reference_with_minimal_data(): void
    {
        $fundingRef = ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
            'position' => 0,
        ]);

        $this->assertNull($fundingRef->funder_identifier);
        $this->assertNull($fundingRef->funder_identifier_type);
        $this->assertNull($fundingRef->award_number);
        $this->assertNull($fundingRef->award_uri);
        $this->assertNull($fundingRef->award_title);
    }

    /**
     * Test creating multiple funding references with correct positions
     */
    public function test_can_create_multiple_funding_references(): void
    {
        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'European Research Council',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'funder_identifier_type' => 'Crossref Funder ID',
            'position' => 0,
        ]);

        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'National Science Foundation',
            'funder_identifier' => 'https://ror.org/021nxhr62',
            'funder_identifier_type' => 'ROR',
            'position' => 1,
        ]);

        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'German Research Foundation',
            'position' => 2,
        ]);

        $this->assertCount(3, $this->resource->fresh()->fundingReferences);

        $fundingRefs = $this->resource->fresh()->fundingReferences->sortBy('position');
        $this->assertEquals('European Research Council', $fundingRefs->first()->funder_name);
        $this->assertEquals('German Research Foundation', $fundingRefs->last()->funder_name);
    }

    /**
     * Test updating funding references
     */
    public function test_can_update_funding_references(): void
    {
        $fundingRef = ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Old Funder Name',
            'funder_identifier' => 'https://ror.org/old',
            'funder_identifier_type' => 'ROR',
            'position' => 0,
        ]);

        // Delete old and create new (simulating update in ResourceController)
        ResourceFundingReference::where('resource_id', $this->resource->id)->delete();

        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'New Funder Name',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'funder_identifier_type' => 'Crossref Funder ID',
            'award_number' => 'NEW-123',
            'position' => 0,
        ]);

        $this->assertDatabaseMissing('resource_funding_references', [
            'funder_name' => 'Old Funder Name',
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'funder_name' => 'New Funder Name',
            'funder_identifier_type' => 'Crossref Funder ID',
            'award_number' => 'NEW-123',
        ]);
    }

    /**
     * Test deleting all funding references
     */
    public function test_can_delete_all_funding_references(): void
    {
        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Funder to Remove',
            'position' => 0,
        ]);

        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Another Funder to Remove',
            'position' => 1,
        ]);

        $this->assertCount(2, $this->resource->fresh()->fundingReferences);

        ResourceFundingReference::where('resource_id', $this->resource->id)->delete();

        $this->assertCount(0, $this->resource->fresh()->fundingReferences);
        $this->assertDatabaseMissing('resource_funding_references', [
            'resource_id' => $this->resource->id,
        ]);
    }

    /**
     * Test relationship between Resource and FundingReferences
     */
    public function test_resource_funding_reference_relationship(): void
    {
        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Test Funder',
            'position' => 0,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $this->resource->fundingReferences);
        $this->assertCount(1, $this->resource->fundingReferences);
        $this->assertEquals('Test Funder', $this->resource->fundingReferences->first()->funder_name);
    }

    /**
     * Test different funder identifier types
     */
    public function test_supports_multiple_funder_identifier_types(): void
    {
        $types = ['ROR', 'Crossref Funder ID', 'ISNI', 'GRID', 'Other'];

        foreach ($types as $index => $type) {
            ResourceFundingReference::create([
                'resource_id' => $this->resource->id,
                'funder_name' => "Funder with {$type}",
                'funder_identifier' => "https://example.org/{$type}",
                'funder_identifier_type' => $type,
                'position' => $index,
            ]);
        }

        $this->assertCount(5, $this->resource->fresh()->fundingReferences);

        foreach ($types as $type) {
            $this->assertDatabaseHas('resource_funding_references', [
                'resource_id' => $this->resource->id,
                'funder_identifier_type' => $type,
            ]);
        }
    }

    /**
     * Test cascade delete when resource is deleted
     */
    public function test_funding_references_deleted_when_resource_deleted(): void
    {
        ResourceFundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Test Funder',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('resource_funding_references', [
            'resource_id' => $this->resource->id,
        ]);

        $this->resource->delete();

        $this->assertDatabaseMissing('resource_funding_references', [
            'resource_id' => $this->resource->id,
        ]);
    }
}
