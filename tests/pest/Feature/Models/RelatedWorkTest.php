<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;

covers(RelatedIdentifier::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();

    // Create lookup records for FK constraints
    $this->doiType = IdentifierType::create([
        'name' => 'DOI',
        'slug' => 'doi',
        'is_active' => true,
    ]);

    $this->citesType = RelationType::create([
        'name' => 'Cites',
        'slug' => 'cites',
        'is_active' => true,
    ]);

    $this->isSupplementToType = RelationType::create([
        'name' => 'IsSupplementTo',
        'slug' => 'is-supplement-to',
        'is_active' => true,
    ]);

    $this->isSupplementedByType = RelationType::create([
        'name' => 'IsSupplementedBy',
        'slug' => 'is-supplemented-by',
        'is_active' => true,
    ]);
});

describe('CRUD Operations', function () {
    it('can create a related identifier', function () {
        $relatedId = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        expect($relatedId)->toBeInstanceOf(RelatedIdentifier::class);
    });

    it('can update related identifiers by delete-and-recreate', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/old',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/new',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->isSupplementedByType->id,
            'position' => 0,
        ]);

        $this->assertDatabaseMissing('related_identifiers', [
            'identifier' => '10.1111/old',
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'identifier' => '10.2222/new',
        ]);
    });

    it('can delete all related identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/to.remove',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        $this->assertDatabaseMissing('related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);
    });
});

describe('Positioning', function () {
    it('maintains correct positions for identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/first',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 1,
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'identifier' => '10.1111/first',
            'position' => 0,
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'identifier' => '10.2222/second',
            'position' => 1,
        ]);
    });

    it('allows same identifier with different relation types', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->isSupplementToType->id,
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
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->isSupplementToType->id,
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
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 0,
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);

        $this->resource->delete();

        $this->assertDatabaseMissing('related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);
    });
});
