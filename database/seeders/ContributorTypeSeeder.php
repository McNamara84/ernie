<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContributorCategory;
use App\Models\ContributorType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Contributor Types (DataCite #7)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/
 */
class ContributorTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite contributorType controlled values with category assignments
        $types = [
            ['name' => 'Contact Person', 'slug' => 'ContactPerson', 'category' => ContributorCategory::PERSON],
            ['name' => 'Data Collector', 'slug' => 'DataCollector', 'category' => ContributorCategory::PERSON],
            ['name' => 'Data Curator', 'slug' => 'DataCurator', 'category' => ContributorCategory::PERSON],
            ['name' => 'Data Manager', 'slug' => 'DataManager', 'category' => ContributorCategory::PERSON],
            ['name' => 'Distributor', 'slug' => 'Distributor', 'category' => ContributorCategory::BOTH],
            ['name' => 'Editor', 'slug' => 'Editor', 'category' => ContributorCategory::PERSON],
            ['name' => 'Hosting Institution', 'slug' => 'HostingInstitution', 'category' => ContributorCategory::INSTITUTION],
            ['name' => 'Producer', 'slug' => 'Producer', 'category' => ContributorCategory::PERSON],
            ['name' => 'Project Leader', 'slug' => 'ProjectLeader', 'category' => ContributorCategory::PERSON],
            ['name' => 'Project Manager', 'slug' => 'ProjectManager', 'category' => ContributorCategory::PERSON],
            ['name' => 'Project Member', 'slug' => 'ProjectMember', 'category' => ContributorCategory::PERSON],
            ['name' => 'Registration Agency', 'slug' => 'RegistrationAgency', 'category' => ContributorCategory::INSTITUTION],
            ['name' => 'Registration Authority', 'slug' => 'RegistrationAuthority', 'category' => ContributorCategory::INSTITUTION],
            ['name' => 'Related Person', 'slug' => 'RelatedPerson', 'category' => ContributorCategory::PERSON],
            ['name' => 'Researcher', 'slug' => 'Researcher', 'category' => ContributorCategory::PERSON],
            ['name' => 'Research Group', 'slug' => 'ResearchGroup', 'category' => ContributorCategory::INSTITUTION],
            ['name' => 'Rights Holder', 'slug' => 'RightsHolder', 'category' => ContributorCategory::BOTH],
            ['name' => 'Sponsor', 'slug' => 'Sponsor', 'category' => ContributorCategory::BOTH],
            ['name' => 'Supervisor', 'slug' => 'Supervisor', 'category' => ContributorCategory::PERSON],
            ['name' => 'Translator', 'slug' => 'Translator', 'category' => ContributorCategory::PERSON],
            ['name' => 'Work Package Leader', 'slug' => 'WorkPackageLeader', 'category' => ContributorCategory::PERSON],
            ['name' => 'Other', 'slug' => 'Other', 'category' => ContributorCategory::BOTH],
        ];

        foreach ($types as $type) {
            ContributorType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'category' => $type['category'],
                ]
            );
        }
    }
}
