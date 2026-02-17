<?php

declare(strict_types=1);

use App\Models\FundingReference;
use App\Models\Resource;

covers(FundingReference::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
});

describe('CRUD Operations', function () {
    it('can create a funding reference with all fields', function () {
        $fundingRef = FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft (DFG)',
            'funder_identifier' => 'https://ror.org/018mejw64',
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

        expect($fundingRef)->toBeInstanceOf(FundingReference::class);
    });

    it('can create a funding reference with minimal data', function () {
        $fundingRef = FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $this->resource->id,
            'funder_name' => 'Example Funder',
        ]);

        expect($fundingRef->funder_identifier)->toBeNull()
            ->and($fundingRef->award_number)->toBeNull()
            ->and($fundingRef->award_uri)->toBeNull()
            ->and($fundingRef->award_title)->toBeNull();
    });

    it('can create multiple funding references', function () {
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

        $fundingRefs = $this->resource->fresh()->fundingReferences;

        expect($fundingRefs)->toHaveCount(3);

        $funderNames = $fundingRefs->pluck('funder_name')->toArray();
        expect($funderNames)->toContain('European Research Council')
            ->toContain('National Science Foundation')
            ->toContain('German Research Foundation');
    });

    it('can update funding references by delete-and-recreate', function () {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Old Funder Name',
            'funder_identifier' => 'https://ror.org/old',
        ]);

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
    });

    it('can delete all funding references', function () {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Funder to Remove',
        ]);

        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Another Funder to Remove',
        ]);

        expect($this->resource->fresh()->fundingReferences)->toHaveCount(2);

        FundingReference::where('resource_id', $this->resource->id)->delete();

        expect($this->resource->fresh()->fundingReferences)->toHaveCount(0);

        $this->assertDatabaseMissing('funding_references', [
            'resource_id' => $this->resource->id,
        ]);
    });
});

describe('Relationships', function () {
    it('has a resource relationship returning a collection', function () {
        FundingReference::create([
            'resource_id' => $this->resource->id,
            'funder_name' => 'Test Funder',
        ]);

        expect($this->resource->fundingReferences)
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class)
            ->toHaveCount(1)
            ->and($this->resource->fundingReferences->first()->funder_name)->toBe('Test Funder');
    });

    it('cascades delete when resource is deleted', function () {
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
    });
});
