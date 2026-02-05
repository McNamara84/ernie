<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Portal test data seeder for generating 100 test resources.
 *
 * Creates a mix of DOI datasets and IGSN samples with varied metadata,
 * geo-locations, and publication years for comprehensive portal testing.
 *
 * Usage: php artisan db:seed --class=PortalTestDataSeeder
 *
 * DEVELOPMENT ONLY - Do not run in production!
 */
class PortalTestDataSeeder extends Seeder
{
    private const TARGET_TOTAL = 100;

    private ResourceType $datasetType;

    private ResourceType $physicalObjectType;

    private Language $language;

    private Publisher $publisher;

    private TitleType $mainTitleType;

    private User $user;

    /** @var list<array{name: string, lat: float, lng: float}> */
    private array $locations = [
        ['name' => 'Potsdam, Germany', 'lat' => 52.39, 'lng' => 13.06],
        ['name' => 'Munich, Germany', 'lat' => 48.14, 'lng' => 11.58],
        ['name' => 'Berlin, Germany', 'lat' => 52.52, 'lng' => 13.40],
        ['name' => 'Hamburg, Germany', 'lat' => 53.55, 'lng' => 9.99],
        ['name' => 'Frankfurt, Germany', 'lat' => 50.11, 'lng' => 8.68],
        ['name' => 'Vienna, Austria', 'lat' => 48.21, 'lng' => 16.37],
        ['name' => 'Zurich, Switzerland', 'lat' => 47.37, 'lng' => 8.54],
        ['name' => 'Paris, France', 'lat' => 48.86, 'lng' => 2.35],
        ['name' => 'London, UK', 'lat' => 51.51, 'lng' => -0.13],
        ['name' => 'Rome, Italy', 'lat' => 41.90, 'lng' => 12.50],
        ['name' => 'Madrid, Spain', 'lat' => 40.42, 'lng' => -3.70],
        ['name' => 'Stockholm, Sweden', 'lat' => 59.33, 'lng' => 18.07],
        ['name' => 'Oslo, Norway', 'lat' => 59.91, 'lng' => 10.75],
        ['name' => 'Copenhagen, Denmark', 'lat' => 55.68, 'lng' => 12.57],
        ['name' => 'Helsinki, Finland', 'lat' => 60.17, 'lng' => 24.94],
        ['name' => 'Tokyo, Japan', 'lat' => 35.68, 'lng' => 139.69],
        ['name' => 'Beijing, China', 'lat' => 39.90, 'lng' => 116.41],
        ['name' => 'Sydney, Australia', 'lat' => -33.87, 'lng' => 151.21],
        ['name' => 'Cape Town, South Africa', 'lat' => -33.93, 'lng' => 18.42],
        ['name' => 'New York, USA', 'lat' => 40.71, 'lng' => -74.01],
        ['name' => 'San Francisco, USA', 'lat' => 37.77, 'lng' => -122.42],
        ['name' => 'Vancouver, Canada', 'lat' => 49.28, 'lng' => -123.12],
        ['name' => 'Rio de Janeiro, Brazil', 'lat' => -22.91, 'lng' => -43.17],
        ['name' => 'Buenos Aires, Argentina', 'lat' => -34.60, 'lng' => -58.38],
        ['name' => 'Mexico City, Mexico', 'lat' => 19.43, 'lng' => -99.13],
        ['name' => 'Delhi, India', 'lat' => 28.61, 'lng' => 77.23],
        ['name' => 'Moscow, Russia', 'lat' => 55.76, 'lng' => 37.62],
        ['name' => 'Cairo, Egypt', 'lat' => 30.04, 'lng' => 31.24],
        ['name' => 'Nairobi, Kenya', 'lat' => -1.29, 'lng' => 36.82],
        ['name' => 'Reykjavik, Iceland', 'lat' => 64.15, 'lng' => -21.94],
    ];

    /** @var list<string> */
    private array $datasetTopics = [
        'Seismological Observations',
        'Groundwater Analysis',
        'Atmospheric Measurements',
        'Soil Composition Study',
        'Climate Time Series',
        'Ocean Current Data',
        'Volcanic Activity Monitoring',
        'Glacier Movement Tracking',
        'Forest Biomass Survey',
        'Urban Heat Island Study',
        'Earthquake Catalog',
        'Geomagnetic Field Data',
        'Paleoclimate Reconstruction',
        'Sediment Transport Analysis',
        'River Discharge Measurements',
        'Land Use Classification',
        'Air Quality Monitoring',
        'Biodiversity Assessment',
        'Geological Mapping',
        'Permafrost Temperature Data',
    ];

    /** @var list<string> */
    private array $sampleTypes = [
        'Rock Core',
        'Sediment Sample',
        'Soil Sample',
        'Water Sample',
        'Ice Core',
        'Volcanic Sample',
        'Mineral Specimen',
        'Fossil Sample',
        'Biological Sample',
        'Fluid Sample',
    ];

    /** @var list<string> */
    private array $materials = [
        'Granite',
        'Basalt',
        'Sandstone',
        'Limestone',
        'Clay',
        'Quartz',
        'Feldspar',
        'Volcanic Ash',
        'Organic Matter',
        'Silicate minerals',
    ];

    public function run(): void
    {
        // Prevent running in production
        if (App::environment('production')) {
            $this->command->error('This seeder cannot be run in production!');

            return;
        }

        // Check how many published resources exist
        $existingCount = Resource::whereHas('landingPage', fn ($q) => $q->where('is_published', true))->count();

        if ($existingCount >= self::TARGET_TOTAL) {
            $this->command->info("PortalTestDataSeeder: Already have {$existingCount} published resources (target: " . self::TARGET_TOTAL . ')');

            return;
        }

        $this->initializeLookupTables();

        $needed = self::TARGET_TOTAL - $existingCount;
        $this->command->info("Creating {$needed} additional resources to reach " . self::TARGET_TOTAL . ' total...');

        // Split between DOI datasets (60%) and IGSNs (40%)
        $doiCount = (int) ceil($needed * 0.6);
        $igsnCount = $needed - $doiCount;

        $created = 0;

        // Create DOI datasets
        for ($i = 0; $i < $doiCount; $i++) {
            if ($this->createDoiDataset($existingCount + $created + 1)) {
                $created++;
            }
        }

        // Create IGSN samples
        for ($i = 0; $i < $igsnCount; $i++) {
            if ($this->createIgsnSample($existingCount + $created + 1)) {
                $created++;
            }
        }

        $this->command->newLine();
        $this->command->info("✓ Created {$created} additional resources.");

        $totalPublished = Resource::whereHas('landingPage', fn ($q) => $q->where('is_published', true))->count();
        $totalWithGeo = Resource::whereHas('geoLocations')->whereHas('landingPage', fn ($q) => $q->where('is_published', true))->count();
        $totalIgsn = Resource::whereHas('resourceType', fn ($q) => $q->where('slug', 'physical-object'))->whereHas('landingPage', fn ($q) => $q->where('is_published', true))->count();

        $this->command->info("Total published resources: {$totalPublished}");
        $this->command->info("  - With geo-locations: {$totalWithGeo}");
        $this->command->info("  - IGSN samples: {$totalIgsn}");
        $this->command->info("  - DOI datasets: " . ($totalPublished - $totalIgsn));
    }

    private function initializeLookupTables(): void
    {
        $this->datasetType = ResourceType::where('slug', 'Dataset')->firstOrFail();
        $this->physicalObjectType = ResourceType::where('slug', 'physical-object')->firstOrFail();
        $this->language = Language::where('code', 'en')->firstOrFail();
        $this->publisher = Publisher::where('is_default', true)->firstOrFail();
        $this->mainTitleType = TitleType::where('slug', 'MainTitle')->firstOrFail();

        // Create test user if none exists
        $this->user = User::first() ?? User::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    private function createDoiDataset(int $index): bool
    {
        $doi = sprintf('10.5880/GFZ.PORTAL.%04d', $index);

        if (Resource::where('doi', $doi)->exists()) {
            return false;
        }

        $topic = $this->datasetTopics[array_rand($this->datasetTopics)];
        $location = $this->locations[array_rand($this->locations)];
        $year = rand(2018, 2025);
        $hasGeo = rand(1, 100) <= 70; // 70% have geo-location

        $resource = Resource::create([
            'doi' => $doi,
            'identifier_type' => 'DOI',
            'publication_year' => $year,
            'resource_type_id' => $this->datasetType->id,
            'publisher_id' => $this->publisher->id,
            'language_id' => $this->language->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // Title
        $resource->titles()->create([
            'value' => "{$topic} from {$location['name']} ({$year})",
            'title_type_id' => $this->mainTitleType->id,
        ]);

        // Creators (1-3 authors for diversity)
        $this->createDiverseCreators($resource, $index);

        // Landing Page
        LandingPage::create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/GFZ',
            'slug' => 'portal-doi-' . $index,
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now()->subDays(rand(1, 365)),
        ]);

        // GeoLocation (70% of datasets) - diversified types
        if ($hasGeo) {
            $this->createDiverseGeoLocation($resource, $location, $index);
        }

        $this->command->info("  ✓ DOI: {$topic} - {$location['name']} ({$year})");

        return true;
    }

    /**
     * Create diverse geolocation types based on index for variety.
     *
     * @param  array{name: string, lat: float, lng: float}  $location
     */
    private function createDiverseGeoLocation(Resource $resource, array $location, int $index): void
    {
        $geoType = $index % 10; // Cycle through different types

        switch ($geoType) {
            case 0:
            case 1:
            case 2:
            case 3:
                // 40% - Simple point
                $resource->geoLocations()->create([
                    'place' => $location['name'],
                    'point_latitude' => $location['lat'],
                    'point_longitude' => $location['lng'],
                ]);
                break;

            case 4:
            case 5:
                // 20% - Bounding box (rectangle) around the point
                $latOffset = rand(5, 20) / 10; // 0.5 to 2.0 degrees
                $lngOffset = rand(5, 20) / 10;
                $resource->geoLocations()->create([
                    'place' => "{$location['name']} Region",
                    'north_bound_latitude' => $location['lat'] + $latOffset,
                    'south_bound_latitude' => $location['lat'] - $latOffset,
                    'east_bound_longitude' => $location['lng'] + $lngOffset,
                    'west_bound_longitude' => $location['lng'] - $lngOffset,
                ]);
                break;

            case 6:
            case 7:
                // 20% - Polygon (triangle or quadrilateral)
                $polygonPoints = $this->createPolygonAroundPoint($location['lat'], $location['lng']);
                $resource->geoLocations()->create([
                    'place' => "{$location['name']} Study Area",
                    'polygon_points' => $polygonPoints,
                ]);
                break;

            case 8:
                // 10% - Multiple geolocations (point + box)
                $resource->geoLocations()->create([
                    'place' => $location['name'],
                    'point_latitude' => $location['lat'],
                    'point_longitude' => $location['lng'],
                ]);
                $latOffset = rand(3, 8) / 10;
                $lngOffset = rand(3, 8) / 10;
                $resource->geoLocations()->create([
                    'place' => "{$location['name']} Extended Area",
                    'north_bound_latitude' => $location['lat'] + $latOffset + 0.5,
                    'south_bound_latitude' => $location['lat'] - $latOffset,
                    'east_bound_longitude' => $location['lng'] + $lngOffset + 0.5,
                    'west_bound_longitude' => $location['lng'] - $lngOffset,
                ]);
                break;

            case 9:
                // 10% - Multiple geolocations (two points)
                $resource->geoLocations()->create([
                    'place' => "{$location['name']} Site A",
                    'point_latitude' => $location['lat'],
                    'point_longitude' => $location['lng'],
                ]);
                $resource->geoLocations()->create([
                    'place' => "{$location['name']} Site B",
                    'point_latitude' => $location['lat'] + (rand(-30, 30) / 10),
                    'point_longitude' => $location['lng'] + (rand(-30, 30) / 10),
                ]);
                break;
        }
    }

    /**
     * Create polygon points around a center point.
     *
     * @return list<array{latitude: float, longitude: float}>
     */
    private function createPolygonAroundPoint(float $centerLat, float $centerLng): array
    {
        $numPoints = rand(3, 6); // Triangle to hexagon
        $radius = rand(5, 15) / 10; // 0.5 to 1.5 degrees
        $points = [];

        for ($i = 0; $i < $numPoints; $i++) {
            $angle = (2 * M_PI * $i) / $numPoints;
            $points[] = [
                'latitude' => round($centerLat + $radius * sin($angle), 6),
                'longitude' => round($centerLng + $radius * cos($angle), 6),
            ];
        }

        return $points;
    }

    private function createIgsnSample(int $index): bool
    {
        $doi = sprintf('10.5880/IGSN.PORTAL.%04d', $index);

        if (Resource::where('doi', $doi)->exists()) {
            return false;
        }

        $sampleType = $this->sampleTypes[array_rand($this->sampleTypes)];
        $material = $this->materials[array_rand($this->materials)];
        $location = $this->locations[array_rand($this->locations)];
        $year = rand(2020, 2025);

        $resource = Resource::create([
            'doi' => $doi,
            'identifier_type' => 'IGSN',
            'publication_year' => $year,
            'resource_type_id' => $this->physicalObjectType->id,
            'publisher_id' => $this->publisher->id,
            'language_id' => $this->language->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // Title
        $resource->titles()->create([
            'value' => "IGSN: {$sampleType} ({$material}) from {$location['name']}",
            'title_type_id' => $this->mainTitleType->id,
        ]);

        // Creators (1-3 authors for diversity)
        $this->createDiverseCreators($resource, $index);

        // IGSN Metadata
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => $sampleType,
            'material' => $material,
            'collection_method' => 'Field Collection',
            'upload_status' => 'registered',
        ]);

        // Landing Page
        LandingPage::create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/IGSN',
            'slug' => 'portal-igsn-' . $index,
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now()->subDays(rand(1, 365)),
        ]);

        // GeoLocation (all IGSN samples have geo-location) - diversified types
        $this->createDiverseGeoLocation($resource, $location, $index + 100);

        $this->command->info("  ✓ IGSN: {$sampleType} ({$material}) - {$location['name']}");

        return true;
    }

    /**
     * Create 1-3 diverse creators for a resource.
     */
    private function createDiverseCreators(Resource $resource, int $index): void
    {
        // Determine number of authors based on index for variety:
        // 40% single author, 35% two authors, 25% three authors
        $authorPattern = $index % 20;
        $authorCount = match (true) {
            $authorPattern < 8 => 1,   // 0-7: single author (40%)
            $authorPattern < 15 => 2,  // 8-14: two authors (35%)
            default => 3,              // 15-19: three authors (25%)
        };

        for ($i = 0; $i < $authorCount; $i++) {
            $orcid = $this->generateOptionalOrcid($index, $i);
            $person = Person::create([
                'family_name' => $this->generateLastName($index + $i * 17),
                'given_name' => $this->generateFirstName($index + $i * 13),
                'name_identifier' => $orcid ? "https://orcid.org/{$orcid}" : null,
                'name_identifier_scheme' => $orcid ? 'ORCID' : null,
                'scheme_uri' => $orcid ? 'https://orcid.org/' : null,
            ]);
            $resource->creators()->create([
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'position' => $i + 1,
            ]);
        }
    }

    /**
     * Generate optional ORCID based on index (50% of first authors, 30% of others).
     */
    private function generateOptionalOrcid(int $resourceIndex, int $authorIndex): ?string
    {
        // First author: 50% chance of ORCID
        // Other authors: 30% chance of ORCID
        $chance = $authorIndex === 0 ? 50 : 30;
        if (rand(1, 100) > $chance) {
            return null;
        }

        // Generate a plausible ORCID format: 0000-000X-XXXX-XXXX
        return sprintf(
            '0000-%04d-%04d-%04d',
            $resourceIndex % 10000,
            ($authorIndex + 1) * 1111,
            rand(1000, 9999)
        );
    }

    private function generateFirstName(int $seed): string
    {
        $names = [
            'Anna', 'Max', 'Julia', 'Paul', 'Emma', 'Lukas', 'Sophie', 'Felix', 'Maria', 'Jan',
            'Laura', 'David', 'Sarah', 'Michael', 'Lisa', 'Thomas', 'Nina', 'Alexander', 'Hannah', 'Daniel',
            'Elena', 'Markus', 'Katharina', 'Stefan', 'Claudia', 'Andreas', 'Martina', 'Peter', 'Sabine', 'Christian',
        ];

        return $names[$seed % count($names)];
    }

    private function generateLastName(int $seed): string
    {
        $names = [
            'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Schulz', 'Hoffmann',
            'Koch', 'Richter', 'Klein', 'Wolf', 'Braun', 'Zimmermann', 'Krüger', 'Hartmann', 'Lange', 'Werner',
            'Schwarz', 'Neumann', 'Schmitz', 'Krause', 'Peters', 'Maier', 'Huber', 'Fuchs', 'Vogel', 'Frank',
        ];

        return $names[$seed % count($names)];
    }
}
