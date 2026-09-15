<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RelationType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Relation Types (DataCite #12)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/
 */
class RelationTypeSeeder extends Seeder
{
    /**
     * DataCite 4.7 relationType controlled values and definitions.
     *
     * @var list<array{name: string, slug: string, description: string}>
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/appendices/appendix-1/relationType/
     */
    public const TYPES = [
        ['name' => 'Is Cited By', 'slug' => 'IsCitedBy', 'description' => 'Indicates that B includes A in a citation'],
        ['name' => 'Cites', 'slug' => 'Cites', 'description' => 'Indicates that A includes B in a citation'],
        ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo', 'description' => 'Indicates that A is a supplement to B'],
        ['name' => 'Is Supplemented By', 'slug' => 'IsSupplementedBy', 'description' => 'Indicates that B is a supplement to A'],
        ['name' => 'Is Translation Of', 'slug' => 'IsTranslationOf', 'description' => 'Indicates A is a translation of B'],
        ['name' => 'Is Continued By', 'slug' => 'IsContinuedBy', 'description' => 'Indicates A is continued by the work B'],
        ['name' => 'Continues', 'slug' => 'Continues', 'description' => 'Indicates A is a continuation of the work B'],
        ['name' => 'Is Described By', 'slug' => 'IsDescribedBy', 'description' => 'Indicates A is described by B'],
        ['name' => 'Describes', 'slug' => 'Describes', 'description' => 'Indicates A describes B'],
        ['name' => 'Has Metadata', 'slug' => 'HasMetadata', 'description' => 'Indicates resource A has additional metadata B'],
        ['name' => 'Is Metadata For', 'slug' => 'IsMetadataFor', 'description' => 'Indicates additional metadata A for a resource B'],
        ['name' => 'Has Version', 'slug' => 'HasVersion', 'description' => 'Indicates A has a version B'],
        ['name' => 'Is Version Of', 'slug' => 'IsVersionOf', 'description' => 'Indicates A is a version of B'],
        ['name' => 'Is New Version Of', 'slug' => 'IsNewVersionOf', 'description' => 'Indicates A is a new edition of B, where the new edition has been modified or updated'],
        ['name' => 'Is Previous Version Of', 'slug' => 'IsPreviousVersionOf', 'description' => 'Indicates A is a previous edition of B'],
        ['name' => 'Is Part Of', 'slug' => 'IsPartOf', 'description' => 'Indicates A is a portion of B; may be used for elements of a series'],
        ['name' => 'Has Part', 'slug' => 'HasPart', 'description' => 'Indicates A includes the part B'],
        ['name' => 'Has Translation', 'slug' => 'HasTranslation', 'description' => 'Indicates A has a translation B'],
        ['name' => 'Is Published In', 'slug' => 'IsPublishedIn', 'description' => 'Indicates A is published inside B, but is independent of other things published inside of B'],
        ['name' => 'Is Referenced By', 'slug' => 'IsReferencedBy', 'description' => 'Indicates A is used as a source of information by B'],
        ['name' => 'References', 'slug' => 'References', 'description' => 'Indicates B is used as a source of information for A'],
        ['name' => 'Is Documented By', 'slug' => 'IsDocumentedBy', 'description' => 'Indicates B is documentation about/explaining A'],
        ['name' => 'Documents', 'slug' => 'Documents', 'description' => 'Indicates A is documentation about/explaining B'],
        ['name' => 'Is Compiled By', 'slug' => 'IsCompiledBy', 'description' => 'Indicates B is used to compile or create A'],
        ['name' => 'Compiles', 'slug' => 'Compiles', 'description' => 'Indicates B is the result of a compile or creation event using A'],
        ['name' => 'Is Variant Form Of', 'slug' => 'IsVariantFormOf', 'description' => 'Indicates A is a variant or different form of B'],
        ['name' => 'Is Original Form Of', 'slug' => 'IsOriginalFormOf', 'description' => 'Indicates A is the original form of B'],
        ['name' => 'Is Identical To', 'slug' => 'IsIdenticalTo', 'description' => 'Indicates that A is identical to B, for use when there is a need to register two separate instances of the same resource'],
        ['name' => 'Is Reviewed By', 'slug' => 'IsReviewedBy', 'description' => 'Indicates that A is reviewed by B'],
        ['name' => 'Reviews', 'slug' => 'Reviews', 'description' => 'Indicates that A is a review of B'],
        ['name' => 'Is Derived From', 'slug' => 'IsDerivedFrom', 'description' => 'Indicates B is a source upon which A is based'],
        ['name' => 'Is Source Of', 'slug' => 'IsSourceOf', 'description' => 'Indicates A is a source upon which B is based'],
        ['name' => 'Is Required By', 'slug' => 'IsRequiredBy', 'description' => 'Indicates A is required by B'],
        ['name' => 'Requires', 'slug' => 'Requires', 'description' => 'Indicates A requires B'],
        ['name' => 'Is Obsoleted By', 'slug' => 'IsObsoletedBy', 'description' => 'Indicates A is replaced by B'],
        ['name' => 'Obsoletes', 'slug' => 'Obsoletes', 'description' => 'Indicates A replaces B'],
        ['name' => 'Is Collected By', 'slug' => 'IsCollectedBy', 'description' => 'Indicates A is collected by B'],
        ['name' => 'Collects', 'slug' => 'Collects', 'description' => 'Indicates A collects B'],
        ['name' => 'Other', 'slug' => 'Other', 'description' => 'Indicates that A is related to B and the relationship does not fit into an existing category.'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TYPES as $type) {
            $relationType = RelationType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                ]
            );

            if ($relationType->description !== $type['description']) {
                $relationType->update(['description' => $type['description']]);
            }
        }
    }
}
