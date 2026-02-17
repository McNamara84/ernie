<?php

declare(strict_types=1);

use App\Models\RelatedIdentifier;
use App\Models\Resource;

covers(RelatedIdentifier::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
});

describe('CRUD Operations', function () {
    it('can create a related identifier', function () {
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

        expect($relatedId)->toBeInstanceOf(RelatedIdentifier::class);
    });

    it('can update related identifiers by delete-and-recreate', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/old',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

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
    });

    it('can delete all related identifiers', function () {
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
    });
});

describe('Positioning', function () {
    it('maintains correct positions for identifiers', function () {
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
    });

    it('allows same identifier with different relation types', function () {
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

        expect(RelatedIdentifier::where('resource_id', $this->resource->id)->count())->toBe(2);
    });
});

describe('Relationships', function () {
    it('has many related identifiers ordered by position', function () {
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

        $this->resource->refresh();

        $relatedIdentifiers = $this->resource->relatedIdentifiers()->get();

        expect($relatedIdentifiers)->toHaveCount(2)
            ->and($relatedIdentifiers[0]->identifier)->toBe('10.1111/first')
            ->and($relatedIdentifiers[1]->identifier)->toBe('10.2222/second');
    });

    it('cascades delete when resource is deleted', function () {
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
    });
});
