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
});

describe('RelatedIdentifier model', function () {
    test('belongs to resource', function () {
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->resource)
            ->toBeInstanceOf(Resource::class)
            ->and($relatedIdentifier->resource->id)->toBe($this->resource->id);
    });

    test('fillable attributes', function () {
        $relatedIdentifier = new RelatedIdentifier([
            'resource_id' => 1,
            'identifier' => '10.5678/example',
            'identifier_type_id' => 99,
            'relation_type_id' => 88,
            'resource_type_general' => 'Dataset',
            'position' => 5,
        ]);

        expect($relatedIdentifier->resource_id)->toBe(1)
            ->and($relatedIdentifier->identifier)->toBe('10.5678/example')
            ->and($relatedIdentifier->identifier_type_id)->toBe(99)
            ->and($relatedIdentifier->relation_type_id)->toBe(88)
            ->and($relatedIdentifier->resource_type_general)->toBe('Dataset')
            ->and($relatedIdentifier->position)->toBe(5);
    });

    test('position is cast to integer', function () {
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => '3',
        ]);

        expect($relatedIdentifier->position)
            ->toBeInt()
            ->toBe(3);
    });

    test('belongs to identifier type', function () {
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->identifierType)
            ->toBeInstanceOf(IdentifierType::class)
            ->and($relatedIdentifier->identifierType->name)->toBe('DOI');
    });

    test('belongs to relation type', function () {
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->relationType)
            ->toBeInstanceOf(RelationType::class)
            ->and($relatedIdentifier->relationType->name)->toBe('Cites');
    });

    test('ordered by position', function () {
        RelatedIdentifier::create([
            'resource_id' => $this->resource->id,
            'identifier' => '10.3333/third',
            'identifier_type_id' => $this->identifierType->id,
            'relation_type_id' => $this->relationType->id,
            'position' => 2,
        ]);

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

        $ordered = $this->resource->relatedIdentifiers()->get();

        expect($ordered[0]->identifier)->toBe('10.1111/first')
            ->and($ordered[1]->identifier)->toBe('10.2222/second')
            ->and($ordered[2]->identifier)->toBe('10.3333/third');
    });

    test('getOppositeRelationType returns correct pairs', function () {
        expect(RelatedIdentifier::getOppositeRelationType('Cites'))->toBe('IsCitedBy')
            ->and(RelatedIdentifier::getOppositeRelationType('IsCitedBy'))->toBe('Cites')
            ->and(RelatedIdentifier::getOppositeRelationType('NonExistent'))->toBeNull();
    });
});
