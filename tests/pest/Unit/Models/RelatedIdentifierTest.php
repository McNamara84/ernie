<?php

declare(strict_types=1);

use App\Models\RelatedIdentifier;
use App\Models\Resource;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('RelatedIdentifier model', function () {
    test('belongs to resource', function () {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        expect($relatedIdentifier->resource)
            ->toBeInstanceOf(Resource::class)
            ->and($relatedIdentifier->resource->id)->toBe($resource->id);
    });

    test('fillable attributes', function () {
        $relatedIdentifier = new RelatedIdentifier([
            'resource_id' => 1,
            'identifier' => '10.5678/example',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementTo',
            'position' => 5,
        ]);

        expect($relatedIdentifier->resource_id)->toBe(1)
            ->and($relatedIdentifier->identifier)->toBe('10.5678/example')
            ->and($relatedIdentifier->identifier_type)->toBe('DOI')
            ->and($relatedIdentifier->relation_type)->toBe('IsSupplementTo')
            ->and($relatedIdentifier->position)->toBe(5);
    });

    test('position is cast to integer', function () {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => '3',
        ]);

        expect($relatedIdentifier->position)
            ->toBeInt()
            ->toBe(3);
    });

    test('supports all identifier types', function () {
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

    test('supports all relation types', function () {
        $resource = Resource::factory()->create();

        $relationTypes = [
            'Cites', 'IsCitedBy',
            'IsSupplementTo', 'IsSupplementedBy',
            'HasPart', 'IsPartOf',
            'IsNewVersionOf', 'IsPreviousVersionOf', 'IsVersionOf', 'HasVersion',
            'IsVariantFormOf', 'IsOriginalFormOf',
            'IsDerivedFrom', 'IsSourceOf',
            'Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy',
            'Reviews', 'IsReviewedBy',
            'References', 'IsReferencedBy',
            'Requires', 'IsRequiredBy',
            'Compiles', 'IsCompiledBy',
            'Collects', 'IsCollectedBy',
            'Obsoletes', 'IsObsoletedBy',
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

        expect($resource->relatedIdentifiers()->get())->toHaveCount(33);
    });

    test('ordered by position', function () {
        $resource = Resource::factory()->create();

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

        expect($ordered[0]->identifier)->toBe('10.1111/first')
            ->and($ordered[1]->identifier)->toBe('10.2222/second')
            ->and($ordered[2]->identifier)->toBe('10.3333/third');
    });
});
