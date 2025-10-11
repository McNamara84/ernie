<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RelatedIdentifier;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for RelatedIdentifier model
 */
class RelatedIdentifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test RelatedIdentifier belongs to Resource
     */
    public function test_belongs_to_resource(): void
    {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => 0,
        ]);

        $this->assertInstanceOf(Resource::class, $relatedIdentifier->resource);
        $this->assertEquals($resource->id, $relatedIdentifier->resource->id);
    }

    /**
     * Test fillable attributes
     */
    public function test_fillable_attributes(): void
    {
        $relatedIdentifier = new RelatedIdentifier([
            'resource_id' => 1,
            'identifier' => '10.5678/example',
            'identifier_type' => 'DOI',
            'relation_type' => 'IsSupplementTo',
            'position' => 5,
        ]);

        $this->assertEquals(1, $relatedIdentifier->resource_id);
        $this->assertEquals('10.5678/example', $relatedIdentifier->identifier);
        $this->assertEquals('DOI', $relatedIdentifier->identifier_type);
        $this->assertEquals('IsSupplementTo', $relatedIdentifier->relation_type);
        $this->assertEquals(5, $relatedIdentifier->position);
    }

    /**
     * Test casts for position field
     */
    public function test_position_is_cast_to_integer(): void
    {
        $resource = Resource::factory()->create();
        $relatedIdentifier = RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type' => 'DOI',
            'relation_type' => 'Cites',
            'position' => '3',
        ]);

        $this->assertIsInt($relatedIdentifier->position);
        $this->assertEquals(3, $relatedIdentifier->position);
    }

    /**
     * Test creating related identifier with all identifier types
     */
    public function test_supports_all_identifier_types(): void
    {
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

            $this->assertEquals($type, $identifier->identifier_type);
        }

        $this->assertCount(19, $resource->relatedIdentifiers()->get());
    }

    /**
     * Test creating related identifier with all relation types
     */
    public function test_supports_all_relation_types(): void
    {
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

            $this->assertEquals($type, $identifier->relation_type);
        }

        // Test that all 33 relation types were created
        $this->assertCount(33, $resource->relatedIdentifiers()->get());
    }

    /**
     * Test ordering by position
     */
    public function test_ordered_by_position(): void
    {
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

        $this->assertEquals('10.1111/first', $ordered[0]->identifier);
        $this->assertEquals('10.2222/second', $ordered[1]->identifier);
        $this->assertEquals('10.3333/third', $ordered[2]->identifier);
    }
}
