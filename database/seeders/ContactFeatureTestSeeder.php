<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContributorType;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder for testing the Contact Information feature on Landing Pages.
 * 
 * Creates a complete resource with:
 * - Title and description
 * - Creators
 * - Contact Person contributors with email addresses
 * - A published landing page
 */
class ContactFeatureTestSeeder extends Seeder
{
    public function run(): void
    {
        // Get or create test user
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        // Get required types
        $datasetType = ResourceType::where('name', 'Dataset')->first()
            ?? ResourceType::first();
        
        $contactPersonType = ContributorType::firstOrCreate(
            ['name' => 'ContactPerson'],
            ['slug' => 'ContactPerson']
        );

        $abstractType = DescriptionType::firstOrCreate(
            ['name' => 'Abstract'],
            ['slug' => 'Abstract']
        );

        // Check if test resource already exists
        $existingResource = Resource::where('doi', '10.5880/GFZ.TEST.CONTACT.2025')->first();
        if ($existingResource) {
            $this->command->info('Test resource already exists!');
            $this->command->info('Resource ID: ' . $existingResource->id);
            $this->command->info('Landing Page URL: https://localhost:3333/ernie/landing-page/' . $existingResource->id);
            return;
        }

        // Create the resource
        $resource = Resource::create([
            'created_by_user_id' => $user->id,
            'resource_type_id' => $datasetType?->id,
            'doi' => '10.5880/GFZ.TEST.CONTACT.2025',
            'publication_year' => 2025,
        ]);

        // Add main title
        $resource->titles()->create([
            'value' => 'Test Dataset for Contact Feature',
            'title_type_id' => 1, // Main Title
        ]);

        // Add description/abstract
        $resource->descriptions()->create([
            'value' => 'This is a test dataset created specifically for testing the Contact Information feature on landing pages. It includes multiple contact persons that visitors can reach out to without exposing email addresses.',
            'description_type_id' => $abstractType->id,
        ]);

        // Create main author/creator
        $mainAuthor = Person::firstOrCreate([
            'name_identifier' => '0000-0001-2345-6789',
        ], [
            'given_name' => 'Jane',
            'family_name' => 'Researcher',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $mainAuthor->id,
            'position' => 1,
        ]);

        // Create Contact Person 1
        $contactPerson1 = Person::firstOrCreate([
            'name_identifier' => '0000-0002-1234-5678',
        ], [
            'given_name' => 'Max',
            'family_name' => 'Mustermann',
            'name_identifier_scheme' => 'ORCID',
        ]);

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contactPersonType->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contactPerson1->id,
            'email' => 'max.mustermann@gfz-potsdam.de',
            'position' => 1,
        ]);

        // Create Contact Person 2
        $contactPerson2 = Person::firstOrCreate([
            'given_name' => 'Erika',
            'family_name' => 'Musterfrau',
        ]);

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contactPersonType->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contactPerson2->id,
            'email' => 'erika.musterfrau@gfz-potsdam.de',
            'position' => 2,
        ]);

        // Create Contact Person 3 (without email - should not appear in contact section)
        $contactPerson3 = Person::firstOrCreate([
            'given_name' => 'No',
            'family_name' => 'Email',
        ]);

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contactPersonType->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $contactPerson3->id,
            'email' => null, // No email - should not appear in contact section
            'position' => 3,
        ]);

        // Create published landing page
        LandingPage::create([
            'resource_id' => $resource->id,
            'slug' => 'test-contact-feature-' . $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test-contact-feature/',
            'preview_token' => bin2hex(random_bytes(32)),
            'published_at' => now(),
        ]);

        $this->command->info('Contact Feature Test Data created successfully!');
        $this->command->info('');
        $this->command->info('Resource ID: ' . $resource->id);
        $this->command->info('Landing Page URL: https://localhost:3333/ernie/landing-page/' . $resource->id);
        $this->command->info('');
        $this->command->info('Contact Persons:');
        $this->command->info('  1. Max Mustermann (max.mustermann@gfz-potsdam.de)');
        $this->command->info('  2. Erika Musterfrau (erika.musterfrau@gfz-potsdam.de)');
        $this->command->info('  3. No Email (no email - should NOT appear in contact section)');
    }
}
