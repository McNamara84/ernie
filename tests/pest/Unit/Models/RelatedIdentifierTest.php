<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;

covers(RelatedIdentifier::class);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Relationships', function () {
    it('belongs to a resource', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->resource)->toBeInstanceOf(Resource::class);
        expect($relatedIdentifier->resource->id)->toBe($resource->id);
    });

    it('belongs to an identifier type', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->identifierType)->toBeInstanceOf(IdentifierType::class);
        expect($relatedIdentifier->identifierType->slug)->toBe('DOI');
    });

    it('belongs to a relation type', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 0,
        ]);

        expect($relatedIdentifier->relationType)->toBeInstanceOf(RelationType::class);
        expect($relatedIdentifier->relationType->slug)->toBe('Cites');
    });
});

describe('Fillable attributes', function () {
    it('allows mass assignment of all fields', function () {
        $relatedIdentifier = new RelatedIdentifier([
            'resource_id' => 1,
            'identifier' => '10.5678/example',
            'identifier_type_id' => 1,
            'relation_type_id' => 1,
            'resource_type_general' => 'Dataset',
            'position' => 5,
        ]);

        expect($relatedIdentifier->resource_id)->toBe(1);
        expect($relatedIdentifier->identifier)->toBe('10.5678/example');
        expect($relatedIdentifier->identifier_type_id)->toBe(1);
        expect($relatedIdentifier->relation_type_id)->toBe(1);
        expect($relatedIdentifier->resource_type_general)->toBe('Dataset');
        expect($relatedIdentifier->position)->toBe(5);
    });
});

describe('Casts', function () {
    it('casts position to integer', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => '3',
        ]);

        expect($relatedIdentifier->position)->toBeInt();
        expect($relatedIdentifier->position)->toBe(3);
    });
});

describe('Identifier types', function () {
    it('supports various identifier types via relationship', function () {
        $resource = Resource::factory()->create();
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        $identifierTypeSlugs = ['ARK', 'DOI', 'Handle', 'URL', 'URN'];

        foreach ($identifierTypeSlugs as $index => $slug) {
            $identifierType = IdentifierType::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);

            $identifier = RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => "test-{$slug}",
                'identifier_type_id' => $identifierType->id,
                'relation_type_id' => $relationType->id,
                'position' => $index,
            ]);

            expect($identifier->identifierType->slug)->toBe($slug);
        }

        expect($resource->relatedIdentifiers()->get())->toHaveCount(5);
    });
});

describe('Relation types', function () {
    it('supports various relation types via relationship', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        $relationTypeSlugs = [
            'Cites', 'IsCitedBy', 'IsSupplementTo', 'IsSupplementedBy',
            'HasPart', 'IsPartOf', 'References', 'IsReferencedBy',
        ];

        foreach ($relationTypeSlugs as $index => $slug) {
            $relationType = RelationType::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);

            $identifier = RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => "test-{$index}",
                'identifier_type_id' => $identifierType->id,
                'relation_type_id' => $relationType->id,
                'position' => $index,
            ]);

            expect($identifier->relationType->slug)->toBe($slug);
        }

        expect($resource->relatedIdentifiers()->get())->toHaveCount(8);
    });
});

describe('Bidirectional pairs', function () {
    it('returns opposite relation type for known pairs', function () {
        expect(RelatedIdentifier::getOppositeRelationType('Cites'))->toBe('IsCitedBy');
        expect(RelatedIdentifier::getOppositeRelationType('IsCitedBy'))->toBe('Cites');
        expect(RelatedIdentifier::getOppositeRelationType('HasPart'))->toBe('IsPartOf');
        expect(RelatedIdentifier::getOppositeRelationType('IsPartOf'))->toBe('HasPart');
    });

    it('returns null for unknown relation type', function () {
        expect(RelatedIdentifier::getOppositeRelationType('UnknownType'))->toBeNull();
    });
});

describe('Ordering', function () {
    it('orders by position', function () {
        $resource = Resource::factory()->create();
        $identifierType = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);
        $relationType = RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]);

        // Create in random order
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.3333/third',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 2,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1111/first',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.2222/second',
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => 1,
        ]);

        $ordered = $resource->relatedIdentifiers()->get();

        expect($ordered[0]->identifier)->toBe('10.1111/first');
        expect($ordered[1]->identifier)->toBe('10.2222/second');
        expect($ordered[2]->identifier)->toBe('10.3333/third');
    });
});
