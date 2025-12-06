<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FundingReference;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for Funding Reference functionality (DataCite #19)
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
        $fundingRef = FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft (DFG)',
            'funder_identifier' => 'https://ror.org/018mejw64',
            // funder_identifier_type_id would be set via relationship
            'award_number' => 'ERC-2021-STG-101234567',
            'award_uri' => 'https://cordis.europa.eu/project/id/101234567',
            'award_title' => 'Innovative Research in AI Systems',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $this->resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft (DFG)',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'award_number' => 'ERC-2021-STG-101234567',
            'award_uri' => 'https://cordis.europa.eu/project/id/101234567',
            'award_title' => 'Innovative Research in AI Systems',
        ]);

        $this->assertInstanceOf(FundingReference::class, $fundingRef);
    }

    /**
     * Test creating funding reference with minimal data (only required fields)
     */
    public function test_can_create_funding_reference_with_minimal_data(): void
    {
        $fundingRef = FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
        ]);

        $this->assertNull($fundingRef->funder_identifier);
        $this->assertNull($fundingRef->award_number);
        $this->assertNull($fundingRef->award_uri);
        $this->assertNull($fundingRef->award_title);
    }

    /**
     * Test creating multiple funding references
     */
    public function test_can_create_multiple_funding_references(): void
    {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'European Research Council',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
        ]);

        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'National Science Foundation',
            'funder_identifier' => 'https://ror.org/021nxhr62',
        ]);

        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'German Research Foundation',
        ]);

        $this->assertCount(3, $this->resource->fresh()->fundingReferences);

        $fundingRefs = $this->resource->fresh()->fundingReferences;
        $funderNames = $fundingRefs->pluck('funder_name')->toArray();
        $this->assertContains('European Research Council', $funderNames);
        $this->assertContains('National Science Foundation', $funderNames);
        $this->assertContains('German Research Foundation', $funderNames);
    }

    /**
     * Test updating funding references
     */
    public function test_can_update_funding_references(): void
    {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Old Funder Name',
            'funder_identifier' => 'https://ror.org/old',
        ]);

        // Delete old and create new (simulating update in ResourceController)
        FundingReference::where('resource_id', $this->resource->id)->delete();

        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'New Funder Name',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'award_number' => 'NEW-123',
        ]);

        $this->assertDatabaseMissing('funding_references', [
            'funder_name' => 'Old Funder Name',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'funder_name' => 'New Funder Name',
            'award_number' => 'NEW-123',
        ]);
    }

    /**
     * Test deleting all funding references
     */
    public function test_can_delete_all_funding_references(): void
    {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Funder to Remove',
        ]);

        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Another Funder to Remove',
        ]);

        $this->assertCount(2, $this->resource->fresh()->fundingReferences);

        FundingReference::where('resource_id', $this->resource->id)->delete();

        $this->assertCount(0, $this->resource->fresh()->fundingReferences);
        $this->assertDatabaseMissing('funding_references', [
            'resource_id' => $this->resource->id,
        ]);
    }

    /**
     * Test relationship between Resource and FundingReferences
     */
    public function test_resource_funding_reference_relationship(): void
    {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Test Funder',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $this->resource->fundingReferences);
        $this->assertCount(1, $this->resource->fundingReferences);
        $this->assertEquals('Test Funder', $this->resource->fundingReferences->first()->funder_name);
    }

    /**
     * Test cascade delete when resource is deleted
     */
    public function test_funding_references_deleted_when_resource_deleted(): void
    {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Test Funder',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $this->resource->id,
        ]);

        $this->resource->delete();

        $this->assertDatabaseMissing('funding_references', [
            'resource_id' => $this->resource->id,
        ]);
    }
}
