<?php

declare(strict_types=1);

use App\Models\RelatedIdentifier;
use App\Models\Resource;

covers(RelatedIdentifier::class);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Relationships', function () {
    it('belongs to a resource', function () {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        expect($relatedIdentifier->resource)->toBeInstanceOf(Resource::class);
        expect($relatedIdentifier->resource->id)->toBe($resource->id);
    });
});

describe('Fillable attributes', function () {
    it('allows mass assignment of all fields', function () {
        $relatedIdentifier = new RelatedIdentifier([
            'resource_id' => 1,
            'identifier' => '10.5678/example',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementTo',
            'position' => 5,
        ]);

        expect($relatedIdentifier->resource_id)->toBe(1);
        expect($relatedIdentifier->identifier)->toBe('10.5678/example');
        expect($relatedIdentifier->identifier_type)->toBe('DOI');
        expect($relatedIdentifier->relation_type)->toBe('IsSupplementTo');
        expect($relatedIdentifier->position)->toBe(5);
    });
});

describe('Casts', function () {
    it('casts position to integer', function () {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => '3',
        ]);

        expect($relatedIdentifier->position)->toBeInt();
        expect($relatedIdentifier->position)->toBe(3);
    });
});

describe('Identifier types', function () {
    it('supports all identifier types', function () {
        $resource = Resource::factory()->create();

        $identifierTypes = [
            'ARK', 'arXiv', 'bibcode', 'DOI', 'EAN13', 'EISSN', 'Handle',
            'IGSN', 'ISBN', 'ISSN', 'ISTC', 'LISSN', 'LSID', 'PMID', 'PURL',
            'UPC', 'URL', 'URN', 'w3id',
        ];

        foreach ($identifierTypes as $index => $type) {
            $identifier = RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => "test-{$type}",
                'identifier_type' => $type,
                'relation_type' => 'Cites',
                'position' => $index,
            ]);

            expect($identifier->identifier_type)->toBe($type);
        }

        expect($resource->relatedIdentifiers()->get())->toHaveCount(19);
    });
});

describe('Relation types', function () {
    it('supports all relation types', function () {
        $resource = Resource::factory()->create();

        $relationTypes = [
            // Citation Relations (2)
            'Cites', 'IsCitedBy',
            // Supplement Relations (2)
            'IsSupplementTo', 'IsSupplementedBy',
            // Part Relations (2)
            'HasPart', 'IsPartOf',
            // Version Relations (6)
            'IsNewVersionOf', 'IsPreviousVersionOf', 'IsVersionOf', 'HasVersion',
            'IsVariantFormOf', 'IsOriginalFormOf',
            // Derivation Relations (2)
            'IsDerivedFrom', 'IsSourceOf',
            // Documentation Relations (4)
            'Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy',
            // Review Relations (2)
            'Reviews', 'IsReviewedBy',
            // Reference Relations (2)
            'References', 'IsReferencedBy',
            // Requirement Relations (2)
            'Requires', 'IsRequiredBy',
            // Compilation Relations (2)
            'Compiles', 'IsCompiledBy',
            // Collection Relations (2)
            'Collects', 'IsCollectedBy',
            // Obsolescence Relations (2)
            'Obsoletes', 'IsObsoletedBy',
            // Identity Relations (3)
            'IsIdenticalTo', 'IsAlternateIdentifier', 'IsMetadataFor',
        ];

        foreach ($relationTypes as $index => $type) {
            $identifier = RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => "test-{$index}",
                'identifier_type' => 'DOI',
                'relation_type' => $type,
                'position' => $index,
            ]);

            expect($identifier->relation_type)->toBe($type);
        }

        // Test that all 33 relation types were created
        expect($resource->relatedIdentifiers()->get())->toHaveCount(33);
    });
});

describe('Ordering', function () {
    it('orders by position', function () {
        $resource = Resource::factory()->create();

        // Create in random order
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.3333/third',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 2,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1111/first',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.2222/second',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 1,
        ]);

        $ordered = $resource->relatedIdentifiers()->get();

        expect($ordered[0]->identifier)->toBe('10.1111/first');
        expect($ordered[1]->identifier)->toBe('10.2222/second');
        expect($ordered[2]->identifier)->toBe('10.3333/third');
    });
});
