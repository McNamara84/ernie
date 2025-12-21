<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Affiliation;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Database\Seeder;

/**
 * Seeds demo resources with various contact person configurations
 * for testing the Contact Information section on landing pages.
 */
class ContactPersonDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Generate unique ORCIDs using timestamp to avoid conflicts
        $uniqueId = substr(md5((string) time()), 0, 4);

        // Scenario 1: Minimum - Single contact person with email only
        $resource1 = $this->createResourceWithContactPersons(
            title: 'Demo: Single Contact Person (Minimum)',
            contactPersons: [
                [
                    'firstName' => 'Anna',
                    'lastName' => 'Müller',
                    'email' => 'anna.mueller@gfz-potsdam.de',
                    'orcid' => null,
                    'website' => null,
                    'affiliations' => [
                        ['name' => 'GFZ German Research Centre for Geosciences'],
                    ],
                ],
            ]
        );

        // Scenario 2: Single contact person with email, ORCID, and website
        $resource2 = $this->createResourceWithContactPersons(
            title: 'Demo: Contact Person with Full Details',
            contactPersons: [
                [
                    'firstName' => 'Thomas',
                    'lastName' => 'Schmidt',
                    'email' => 'thomas.schmidt@gfz-potsdam.de',
                    'orcid' => "0000-0001-{$uniqueId}-1001",
                    'website' => 'https://www.gfz-potsdam.de/staff/thomas-schmidt',
                    'affiliations' => [
                        ['name' => 'GFZ German Research Centre for Geosciences'],
                        ['name' => 'University of Potsdam'],
                    ],
                ],
            ]
        );

        // Scenario 3: Maximum - Multiple contact persons with various configurations
        $resource3 = $this->createResourceWithContactPersons(
            title: 'Demo: Multiple Contact Persons (Maximum)',
            contactPersons: [
                [
                    'firstName' => 'Maria',
                    'lastName' => 'Weber',
                    'email' => 'maria.weber@gfz-potsdam.de',
                    'orcid' => "0000-0002-{$uniqueId}-2001",
                    'website' => 'https://www.gfz-potsdam.de/staff/maria-weber',
                    'affiliations' => [
                        ['name' => 'GFZ German Research Centre for Geosciences'],
                    ],
                ],
                [
                    'firstName' => 'Klaus',
                    'lastName' => 'Fischer',
                    'email' => 'klaus.fischer@uni-potsdam.de',
                    'orcid' => "0000-0003-{$uniqueId}-3001",
                    'website' => null,
                    'affiliations' => [
                        ['name' => 'University of Potsdam'],
                        ['name' => 'Helmholtz Association'],
                    ],
                ],
                [
                    'firstName' => 'Sabine',
                    'lastName' => 'Hoffmann',
                    'email' => 'sabine.hoffmann@gfz-potsdam.de',
                    'orcid' => null,
                    'website' => 'https://orcid.org/researcher/sabine-hoffmann',
                    'affiliations' => [
                        ['name' => 'GFZ German Research Centre for Geosciences'],
                    ],
                ],
            ]
        );

        // Scenario 4: Contact persons with ROR-IDs for affiliations
        $resource4 = $this->createResourceWithContactPersons(
            title: 'Demo: Contact Persons with ROR Affiliations',
            contactPersons: [
                [
                    'firstName' => 'Elena',
                    'lastName' => 'Berger',
                    'email' => 'elena.berger@gfz-potsdam.de',
                    'orcid' => "0000-0004-{$uniqueId}-4001",
                    'website' => 'https://www.gfz-potsdam.de/staff/elena-berger',
                    'affiliations' => [
                        [
                            'name' => 'GFZ German Research Centre for Geosciences',
                            'identifier' => 'https://ror.org/04z8jg394',
                            'scheme' => 'ROR',
                        ],
                    ],
                ],
                [
                    'firstName' => 'Markus',
                    'lastName' => 'Richter',
                    'email' => 'markus.richter@uni-potsdam.de',
                    'orcid' => "0000-0005-{$uniqueId}-5001",
                    'website' => null,
                    'affiliations' => [
                        [
                            'name' => 'University of Potsdam',
                            'identifier' => 'https://ror.org/03bnmw459',
                            'scheme' => 'ROR',
                        ],
                        [
                            'name' => 'Helmholtz Association',
                            'identifier' => 'https://ror.org/0281dp749',
                            'scheme' => 'ROR',
                        ],
                    ],
                ],
            ]
        );

        $this->command->info('Contact Person Demo Resources created:');
        $this->command->table(
            ['ID', 'Title', 'Contact Persons'],
            [
                [$resource1->id, 'Single Contact (Minimum)', '1'],
                [$resource2->id, 'Full Details', '1'],
                [$resource3->id, 'Multiple Contacts (Maximum)', '3'],
                [$resource4->id, 'With ROR Affiliations', '2'],
            ]
        );
    }

    /**
     * @param  array<int, array{firstName: string, lastName: string, email: string, orcid: ?string, website: ?string, affiliations: array<int, array{name: string, identifier?: string, scheme?: string}>}>  $contactPersons
     */
    private function createResourceWithContactPersons(string $title, array $contactPersons): Resource
    {
        // Get or create lookup table entries
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]
        );

        $publisher = Publisher::firstOrCreate(
            ['name' => 'GFZ Data Services'],
            ['name' => 'GFZ Data Services', 'is_default' => true]
        );

        // Create the resource
        $resource = Resource::create([
            'doi' => '10.5880/demo-'.uniqid(),
            'publication_year' => now()->year,
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publisher_id' => $publisher->id,
            'version' => '1.0',
        ]);

        // Add title (use first TitleType as default, typically "Main Title")
        $titleType = TitleType::first();

        Title::create([
            'resource_id' => $resource->id,
            'value' => $title,
            'title_type_id' => $titleType->id ?? 1,
        ]);

        // Add contact persons as creators with isContact = true
        $position = 1;
        foreach ($contactPersons as $contactData) {
            // Find or create person - include name_identifier in search to avoid unique constraint issues
            $searchCriteria = [
                'given_name' => $contactData['firstName'],
                'family_name' => $contactData['lastName'],
            ];

            if ($contactData['orcid']) {
                $searchCriteria['name_identifier'] = $contactData['orcid'];
            }

            $person = Person::firstOrCreate(
                $searchCriteria,
                [
                    'name_identifier' => $contactData['orcid'],
                    'name_identifier_scheme' => $contactData['orcid'] ? 'ORCID' : null,
                    'scheme_uri' => $contactData['orcid'] ? 'https://orcid.org/' : null,
                ]
            );

            // Update ORCID if person exists but doesn't have one
            if ($contactData['orcid'] && ! $person->name_identifier) {
                $person->update([
                    'name_identifier' => $contactData['orcid'],
                    'name_identifier_scheme' => 'ORCID',
                    'scheme_uri' => 'https://orcid.org/',
                ]);
            }

            // Create ResourceCreator with contact flag
            $creator = ResourceCreator::create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'position' => $position,
                'is_contact' => true,
                'email' => $contactData['email'],
                'website' => $contactData['website'],
            ]);

            // Add affiliations
            foreach ($contactData['affiliations'] as $affiliationData) {
                Affiliation::create([
                    'affiliatable_type' => ResourceCreator::class,
                    'affiliatable_id' => $creator->id,
                    'name' => $affiliationData['name'],
                    'identifier' => $affiliationData['identifier'] ?? null,
                    'identifier_scheme' => $affiliationData['scheme'] ?? null,
                ]);
            }

            $position++;
        }

        // Create published landing page
        LandingPage::create([
            'resource_id' => $resource->id,
            'slug' => 'demo-'.uniqid(),
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now(),
        ]);

        return $resource;
    }
}
