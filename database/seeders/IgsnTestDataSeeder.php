<?php

namespace Database\Seeders;

use App\Models\IgsnMetadata;
use App\Models\Language;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder for creating IGSN test data for portal testing.
 */
class IgsnTestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating IGSN test samples...');

        // Physical Object exists with ID 21
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        if (! $physicalObjectType) {
            $this->command->error('Physical Object resource type not found!');

            return;
        }

        $publisher = Publisher::first();
        $language = Language::first();

        if (! $publisher || ! $language) {
            $this->command->error('Publisher or Language not found! Run base seeders first.');

            return;
        }

        // Create test user if none exists
        $user = User::first();
        if (! $user) {
            $user = User::create([
                'name' => 'Test Admin',
                'email' => 'admin@test.local',
                'password' => bcrypt('password'),
                'role' => 'admin',
            ]);
            $this->command->info('  ✓ Created test admin user (admin@test.local / password)');
        }

        $samples = [
            [
                'title' => 'IGSN: Granite Core Sample from Alpine Fault Zone',
                'sample_type' => 'Rock Core',
                'material' => 'Granite',
                'lat' => -43.4,
                'lng' => 170.2,
                'place' => 'Alpine Fault, New Zealand',
            ],
            [
                'title' => 'IGSN: Sediment Sample from Baltic Sea',
                'sample_type' => 'Sediment Core',
                'material' => 'Clay/Silt',
                'lat' => 55.0,
                'lng' => 15.5,
                'place' => 'Baltic Sea, Denmark',
            ],
            [
                'title' => 'IGSN: Volcanic Ash Sample from Mount Etna',
                'sample_type' => 'Volcanic Sample',
                'material' => 'Volcanic Ash',
                'lat' => 37.75,
                'lng' => 15.0,
                'place' => 'Mount Etna, Sicily',
            ],
            [
                'title' => 'IGSN: Ice Core from Greenland Ice Sheet',
                'sample_type' => 'Ice Core',
                'material' => 'Ice',
                'lat' => 72.5,
                'lng' => -38.5,
                'place' => 'Greenland Ice Sheet',
            ],
            [
                'title' => 'IGSN: Meteorite Fragment - Chelyabinsk',
                'sample_type' => 'Meteorite',
                'material' => 'Chondrite',
                'lat' => 55.1,
                'lng' => 61.4,
                'place' => 'Chelyabinsk Oblast, Russia',
            ],
            [
                'title' => 'IGSN: Deep Sea Manganese Nodule',
                'sample_type' => 'Marine Sample',
                'material' => 'Manganese Oxide',
                'lat' => -10.5,
                'lng' => -150.0,
                'place' => 'Pacific Ocean, Clarion-Clipperton Zone',
            ],
            [
                'title' => 'IGSN: Permafrost Core from Siberia',
                'sample_type' => 'Permafrost Core',
                'material' => 'Frozen Soil',
                'lat' => 68.5,
                'lng' => 133.4,
                'place' => 'Sakha Republic, Siberia',
            ],
            [
                'title' => 'IGSN: Coral Sample from Great Barrier Reef',
                'sample_type' => 'Biological Sample',
                'material' => 'Coral Skeleton',
                'lat' => -18.3,
                'lng' => 147.7,
                'place' => 'Great Barrier Reef, Australia',
            ],
            [
                'title' => 'IGSN: Soil Sample from Amazon Rainforest',
                'sample_type' => 'Soil Sample',
                'material' => 'Tropical Soil',
                'lat' => -3.1,
                'lng' => -60.0,
                'place' => 'Amazon Basin, Brazil',
            ],
            [
                'title' => 'IGSN: Geothermal Fluid Sample from Iceland',
                'sample_type' => 'Fluid Sample',
                'material' => 'Geothermal Water',
                'lat' => 64.0,
                'lng' => -21.0,
                'place' => 'Reykjanes Peninsula, Iceland',
            ],
        ];

        foreach ($samples as $index => $sample) {
            $doi = sprintf('10.5880/IGSN.TEST.%04d', $index + 1);

            // Skip if already exists (idempotency)
            if (Resource::where('doi', $doi)->exists()) {
                $this->command->warn("  → Skipped (exists): {$sample['title']}");

                continue;
            }

            $resource = Resource::create([
                'doi' => $doi,
                'identifier_type' => 'IGSN',
                'publication_year' => 2023 + ($index % 3),
                'resource_type_id' => $physicalObjectType->id,
                'publisher_id' => $publisher->id,
                'language_id' => $language->id,
                'created_by_user_id' => $user->id,
            ]);

            // Title (column is 'value' not 'title')
            $resource->titles()->create([
                'value' => $sample['title'],
                'title_type_id' => 1,
            ]);

            // Creator (Person)
            $person = Person::create([
                'family_name' => 'Sample Collector ' . ($index + 1),
                'given_name' => 'Dr.',
            ]);
            $resource->creators()->create([
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'position' => 1,
            ]);

            // IGSN Metadata
            IgsnMetadata::create([
                'resource_id' => $resource->id,
                'sample_type' => $sample['sample_type'],
                'material' => $sample['material'],
                'collection_method' => 'Field Collection',
                'upload_status' => 'registered',
            ]);

            // Landing Page (published)
            LandingPage::create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/IGSN',
                'slug' => 'igsn-test-' . ($index + 1),
                'template' => 'gfz',
                'is_published' => true,
                'published_at' => now(),
            ]);

            // GeoLocation (column is 'place', not 'geo_location_place')
            $resource->geoLocations()->create([
                'place' => $sample['place'],
                'point_longitude' => $sample['lng'],
                'point_latitude' => $sample['lat'],
            ]);

            $this->command->info("  ✓ Created: {$sample['title']}");
        }

        $this->command->newLine();
        $this->command->info('✓ Created ' . count($samples) . ' IGSN samples.');
    }
}
