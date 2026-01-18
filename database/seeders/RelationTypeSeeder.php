<?php

namespace Database\Seeders;

use App\Models\RelationType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Relation Types (DataCite #12)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/relatedidentifier/
 */
class RelationTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite relationType controlled values
        $types = [
            ['name' => 'Is Cited By', 'slug' => 'IsCitedBy'],
            ['name' => 'Cites', 'slug' => 'Cites'],
            ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo'],
            ['name' => 'Is Supplemented By', 'slug' => 'IsSupplementedBy'],
            ['name' => 'Is Translation Of', 'slug' => 'IsTranslationOf'],
            ['name' => 'Is Continued By', 'slug' => 'IsContinuedBy'],
            ['name' => 'Continues', 'slug' => 'Continues'],
            ['name' => 'Is Described By', 'slug' => 'IsDescribedBy'],
            ['name' => 'Describes', 'slug' => 'Describes'],
            ['name' => 'Has Metadata', 'slug' => 'HasMetadata'],
            ['name' => 'Is Metadata For', 'slug' => 'IsMetadataFor'],
            ['name' => 'Has Version', 'slug' => 'HasVersion'],
            ['name' => 'Is Version Of', 'slug' => 'IsVersionOf'],
            ['name' => 'Is New Version Of', 'slug' => 'IsNewVersionOf'],
            ['name' => 'Is Previous Version Of', 'slug' => 'IsPreviousVersionOf'],
            ['name' => 'Is Part Of', 'slug' => 'IsPartOf'],
            ['name' => 'Has Part', 'slug' => 'HasPart'],
            ['name' => 'Has Translation', 'slug' => 'HasTranslation'],
            ['name' => 'Is Published In', 'slug' => 'IsPublishedIn'],
            ['name' => 'Is Referenced By', 'slug' => 'IsReferencedBy'],
            ['name' => 'References', 'slug' => 'References'],
            ['name' => 'Is Documented By', 'slug' => 'IsDocumentedBy'],
            ['name' => 'Documents', 'slug' => 'Documents'],
            ['name' => 'Is Compiled By', 'slug' => 'IsCompiledBy'],
            ['name' => 'Compiles', 'slug' => 'Compiles'],
            ['name' => 'Is Variant Form Of', 'slug' => 'IsVariantFormOf'],
            ['name' => 'Is Original Form Of', 'slug' => 'IsOriginalFormOf'],
            ['name' => 'Is Identical To', 'slug' => 'IsIdenticalTo'],
            ['name' => 'Is Reviewed By', 'slug' => 'IsReviewedBy'],
            ['name' => 'Reviews', 'slug' => 'Reviews'],
            ['name' => 'Is Derived From', 'slug' => 'IsDerivedFrom'],
            ['name' => 'Is Source Of', 'slug' => 'IsSourceOf'],
            ['name' => 'Is Required By', 'slug' => 'IsRequiredBy'],
            ['name' => 'Requires', 'slug' => 'Requires'],
            ['name' => 'Is Obsoleted By', 'slug' => 'IsObsoletedBy'],
            ['name' => 'Obsoletes', 'slug' => 'Obsoletes'],
            ['name' => 'Is Collected By', 'slug' => 'IsCollectedBy'],
            ['name' => 'Collects', 'slug' => 'Collects'],
        ];

        foreach ($types as $type) {
            RelationType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
