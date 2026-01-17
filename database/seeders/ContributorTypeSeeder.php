<?php

namespace Database\Seeders;

use App\Models\ContributorType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Contributor Types (DataCite #7)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/contributor/
 */
class ContributorTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite contributorType controlled values
        $types = [
            ['name' => 'Contact Person', 'slug' => 'ContactPerson'],
            ['name' => 'Data Collector', 'slug' => 'DataCollector'],
            ['name' => 'Data Curator', 'slug' => 'DataCurator'],
            ['name' => 'Data Manager', 'slug' => 'DataManager'],
            ['name' => 'Distributor', 'slug' => 'Distributor'],
            ['name' => 'Editor', 'slug' => 'Editor'],
            ['name' => 'Hosting Institution', 'slug' => 'HostingInstitution'],
            ['name' => 'Producer', 'slug' => 'Producer'],
            ['name' => 'Project Leader', 'slug' => 'ProjectLeader'],
            ['name' => 'Project Manager', 'slug' => 'ProjectManager'],
            ['name' => 'Project Member', 'slug' => 'ProjectMember'],
            ['name' => 'Registration Agency', 'slug' => 'RegistrationAgency'],
            ['name' => 'Registration Authority', 'slug' => 'RegistrationAuthority'],
            ['name' => 'Related Person', 'slug' => 'RelatedPerson'],
            ['name' => 'Researcher', 'slug' => 'Researcher'],
            ['name' => 'Research Group', 'slug' => 'ResearchGroup'],
            ['name' => 'Rights Holder', 'slug' => 'RightsHolder'],
            ['name' => 'Sponsor', 'slug' => 'Sponsor'],
            ['name' => 'Supervisor', 'slug' => 'Supervisor'],
            ['name' => 'Translator', 'slug' => 'Translator'],
            ['name' => 'Work Package Leader', 'slug' => 'WorkPackageLeader'],
            ['name' => 'Other', 'slug' => 'Other'],
        ];

        foreach ($types as $type) {
            ContributorType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
