<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
    $this->relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);
    $this->supplementType = RelationType::create(['name' => 'IsSupplementedBy', 'slug' => 'IsSupplementedBy', 'is_active' => true]);
    $this->supplementToType = RelationType::create(['name' => 'IsSupplementTo', 'slug' => 'IsSupplementTo', 'is_active' => true]);
});

describe('RelatedIdentifier CRUD', function () {
    test('can create related identifiers', function () {
        $relatedId = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'resource_id' => $this->resource->id,
            'identifier' => '10.5678/related.dataset',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        expect($relatedId)->toBeInstanceOf(RelatedIdentifier::class);
    });

    test('can update related identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/old',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/new',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->supplementType->id,
            'position' => 0,
        ]);

        $this->assertDatabaseMissing('related_identifiers', [
            'identifier' => '10.1111/old',
        ]);

        $this->assertDatabaseHas('related_identifiers', [
            'identifier' => '10.2222/new',
        ]);
    });

    test('can delete all related identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/to.remove',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::where('resource_id', $this->resource->id)->delete();

        $this->assertDatabaseMissing('related_identifiers', [
            'resource_id' => $this->resource->id,
        ]);
    });

    test('maintains positions', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/first',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
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

    test('allows same identifier with different relation types', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/same',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->supplementToType->id,
            'position' => 1,
        ]);

        expect(RelatedIdentifier::where('resource_id', $this->resource->id)->count())->toBe(2);
    });
});

describe('RelatedIdentifier relationships', function () {
    test('resource has many related identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/first',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.2222/second',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->supplementToType->id,
            'position' => 1,
        ]);

        $this->resource->refresh();
        $relatedIdentifiers = $this->resource->relatedIdentifiers()->get();

        expect($relatedIdentifiers)->toHaveCount(2);
        expect($relatedIdentifiers[0]->identifier)->toBe('10.1111/first');
        expect($relatedIdentifiers[1]->identifier)->toBe('10.2222/second');
    });

    test('deleting resource cascades to related identifiers', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1111/test',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
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
