<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Format;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Size;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Comprehensive seeder for creating test resources with various configurations.
 *
 * Creates resources covering many different scenarios:
 * - Minimal resources (mandatory fields only)
 * - Fully populated resources (all possible fields)
 * - Resources with persistent identifiers (ORCID, ROR)
 * - Resources with single/multiple licenses
 * - Resources with single/many authors
 * - Resources with various GeoLocation configurations
 * - Resources with contact persons
 * - Resources with funding references
 * - Resources with related identifiers
 * - Resources with subjects (free-text and controlled vocabularies)
 *
 * This seeder is idempotent - it skips resources that already exist.
 *
 * Usage: php artisan db:seed --class=ComprehensiveResourceSeeder
 *
 * DEVELOPMENT ONLY - Do not run in production!
 */
class ComprehensiveResourceSeeder extends Seeder
{
    private ResourceType $resourceType;

    private Language $language;

    private Publisher $publisher;

    private TitleType $mainTitleType;

    private TitleType $subtitleType;

    private TitleType $alternativeTitleType;

    private DescriptionType $abstractType;

    private DescriptionType $methodsType;

    private ContributorType $contactPersonType;

    private ContributorType $dataCollectorType;

    private DateType $collectedType;

    private DateType $createdType;

    private IdentifierType $doiType;

    private IdentifierType $urlType;

    private RelationType $citesType;

    private RelationType $isSupplementToType;

    private FunderIdentifierType $rorFunderType;

    private FunderIdentifierType $crossrefFunderType;

    private int $createdCount = 0;

    private int $skippedCount = 0;

    public function run(): void
    {
        // Prevent running in production environment
        if (App::environment('production')) {
            $this->command->error('This seeder cannot be run in production environment!');

            return;
        }

        $this->command->info('Creating comprehensive test resources...');
        $this->command->newLine();

        $this->initializeLookupTables();

        // Create all resource scenarios
        $this->createMinimalResource();
        $this->createFullyPopulatedResource();
        $this->createResourceWithManyAuthors();
        $this->createResourceWithSingleAuthor();
        $this->createResourceWithInstitutionalCreator();
        $this->createResourceWithMixedCreators();
        $this->createResourceWithPersistentIdentifiers();
        $this->createResourceWithSingleLicense();
        $this->createResourceWithMultipleLicenses();
        $this->createResourceWithContactPersons();
        $this->createResourceWithGeoLocationPoint();
        $this->createResourceWithGeoLocationBox();
        $this->createResourceWithGeoLocationPolygon();
        $this->createResourceWithMultipleGeoLocations();
        $this->createResourceWithFundingReferences();
        $this->createResourceWithRelatedIdentifiers();
        $this->createResourceWithControlledSubjects();
        $this->createResourceWithFreeTextKeywords();
        $this->createResourceWithMixedSubjects();
        $this->createResourceWithTemporalCoverage();
        $this->createResourceWithMultipleTitles();
        $this->createResourceWithMultipleDescriptions();
        $this->createResourceWithSizesAndFormats();
        $this->createResourceWithoutDoi();
        $this->createResourceWithAffiliations();

        $this->command->newLine();
        $this->command->info("✓ Created {$this->createdCount} new resources, skipped {$this->skippedCount} existing.");
    }

    private function initializeLookupTables(): void
    {
        // Resource Type
        $this->resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        // Language
        $this->language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]
        );

        // Publisher
        $this->publisher = Publisher::firstOrCreate(
            ['name' => 'GFZ Data Services'],
            ['name' => 'GFZ Data Services', 'is_default' => true]
        );

        // Title Types
        $this->mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );
        $this->subtitleType = TitleType::firstOrCreate(
            ['slug' => 'Subtitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle', 'is_active' => true]
        );
        $this->alternativeTitleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        // Description Types
        $this->abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );
        $this->methodsType = DescriptionType::firstOrCreate(
            ['slug' => 'Methods'],
            ['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true]
        );

        // Contributor Types
        $this->contactPersonType = ContributorType::firstOrCreate(
            ['slug' => 'ContactPerson'],
            ['name' => 'Contact Person', 'slug' => 'ContactPerson']
        );
        $this->dataCollectorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector', 'slug' => 'DataCollector']
        );

        // Date Types
        $this->collectedType = DateType::firstOrCreate(
            ['slug' => 'Collected'],
            ['name' => 'Collected', 'slug' => 'Collected']
        );
        $this->createdType = DateType::firstOrCreate(
            ['slug' => 'Created'],
            ['name' => 'Created', 'slug' => 'Created']
        );

        // Identifier Types
        $this->doiType = IdentifierType::firstOrCreate(
            ['slug' => 'DOI'],
            ['name' => 'DOI', 'slug' => 'DOI']
        );
        $this->urlType = IdentifierType::firstOrCreate(
            ['slug' => 'URL'],
            ['name' => 'URL', 'slug' => 'URL']
        );

        // Relation Types
        $this->citesType = RelationType::firstOrCreate(
            ['slug' => 'Cites'],
            ['name' => 'Cites', 'slug' => 'Cites']
        );
        $this->isSupplementToType = RelationType::firstOrCreate(
            ['slug' => 'IsSupplementTo'],
            ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo']
        );

        // Funder Identifier Types
        $this->rorFunderType = FunderIdentifierType::firstOrCreate(
            ['slug' => 'ROR'],
            ['name' => 'ROR', 'slug' => 'ROR']
        );
        $this->crossrefFunderType = FunderIdentifierType::firstOrCreate(
            ['slug' => 'Crossref Funder ID'],
            ['name' => 'Crossref Funder ID', 'slug' => 'Crossref Funder ID']
        );
    }

    /**
     * Scenario 1: Minimal resource with only mandatory fields
     */
    private function createMinimalResource(): void
    {
        $doi = '10.5880/test.minimal.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Minimal Resource - Mandatory Fields Only');

        $person = $this->findOrCreatePerson('John', 'Doe');
        $this->addCreator($resource, $person, 1);

        $this->createLandingPage($resource, 'minimal-resource');
        $this->logCreated('Minimal Resource (mandatory fields only)');
    }

    /**
     * Scenario 2: Fully populated resource with all possible fields
     */
    private function createFullyPopulatedResource(): void
    {
        $doi = '10.5880/test.complete.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Fully Populated Resource - All Fields', '2.1.0');

        // Multiple titles
        $this->addTitle($resource, 'Complete Dataset with All Metadata Fields', $this->subtitleType);
        $this->addTitle($resource, 'Vollständiger Datensatz', $this->alternativeTitleType, 'de');

        // Multiple descriptions
        $this->addDescription($resource, 'This is a comprehensive test dataset containing all possible metadata fields as defined by the DataCite schema 4.6.', $this->abstractType);
        $this->addDescription($resource, 'Data was collected using standardized measurement protocols. Laboratory analysis followed ISO standards.', $this->methodsType);

        // Multiple creators with ORCID and affiliations
        $creator1 = $this->findOrCreatePerson('Maria', 'Schmidt', '0000-0001-2345-6789');
        $creatorRecord1 = $this->addCreator($resource, $creator1, 1, 'maria.schmidt@gfz-potsdam.de');
        $this->addAffiliation($creatorRecord1, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');

        $creator2 = $this->findOrCreatePerson('Thomas', 'Weber', '0000-0002-3456-7890');
        $creatorRecord2 = $this->addCreator($resource, $creator2, 2);
        $this->addAffiliation($creatorRecord2, 'University of Potsdam', 'https://ror.org/03bnmw459', 'ROR');

        // Contributors
        $contributor1 = $this->findOrCreatePerson('Klaus', 'Fischer');
        $this->addContributor($resource, $contributor1, $this->dataCollectorType, 1);

        // Multiple subjects
        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'subject_scheme' => 'GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/seismology-001',
        ]);
        Subject::create(['resource_id' => $resource->id, 'value' => 'earthquake monitoring']);

        // Dates
        ResourceDate::create([
            'resource_id' => $resource->id,
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
            'date_type_id' => $this->collectedType->id,
            'date_information' => 'Field campaign duration',
        ]);

        // GeoLocation
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'GFZ Potsdam',
            'point_longitude' => 13.0661,
            'point_latitude' => 52.3806,
        ]);

        // Funding
        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type_id' => $this->rorFunderType->id,
            'award_number' => 'DFG-123456',
            'award_title' => 'Seismic Monitoring Project',
        ]);

        // Related identifiers
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1000/related-paper',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->isSupplementToType->id,
            'position' => 1,
        ]);

        // Sizes and formats
        Size::create(['resource_id' => $resource->id, 'value' => '2.5 GB']);
        Size::create(['resource_id' => $resource->id, 'value' => '1500 files']);
        Format::create(['resource_id' => $resource->id, 'value' => 'application/zip']);
        Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);

        // License
        $license = $this->findOrCreateLicense('CC-BY-4.0', 'Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/');
        $resource->rights()->attach($license->id);

        $this->createLandingPage($resource, 'fully-populated');
        $this->logCreated('Fully Populated Resource (all fields)');
    }

    /**
     * Scenario 3: Resource with many authors (10+)
     */
    private function createResourceWithManyAuthors(): void
    {
        $doi = '10.5880/test.many-authors.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Large Collaboration Dataset - Many Authors');

        $authors = [
            ['Emma', 'Anderson'], ['Oliver', 'Brown'], ['Sophia', 'Clark'],
            ['William', 'Davis'], ['Isabella', 'Evans'], ['James', 'Foster'],
            ['Mia', 'Garcia'], ['Benjamin', 'Harris'], ['Charlotte', 'Irving'],
            ['Lucas', 'Johnson'], ['Amelia', 'King'], ['Henry', 'Lewis'],
        ];

        foreach ($authors as $index => $author) {
            $person = $this->findOrCreatePerson($author[0], $author[1]);
            $this->addCreator($resource, $person, $index + 1);
        }

        $this->addDescription($resource, 'This dataset was created by a large international collaboration of researchers.', $this->abstractType);
        $this->createLandingPage($resource, 'many-authors');
        $this->logCreated('Resource with many authors (12)');
    }

    /**
     * Scenario 4: Resource with single author
     */
    private function createResourceWithSingleAuthor(): void
    {
        $doi = '10.5880/test.single-author.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Single Author Dataset');

        $person = $this->findOrCreatePerson('Sarah', 'Miller', '0000-0003-4567-8901');
        $creatorRecord = $this->addCreator($resource, $person, 1, 'sarah.miller@example.org', 'https://example.org/~smiller');
        $this->addAffiliation($creatorRecord, 'Max Planck Institute', 'https://ror.org/01hhn8329', 'ROR');

        $this->addDescription($resource, 'A solo research project examining climate patterns.', $this->abstractType);
        $this->createLandingPage($resource, 'single-author');
        $this->logCreated('Resource with single author');
    }

    /**
     * Scenario 5: Resource with institutional creator
     */
    private function createResourceWithInstitutionalCreator(): void
    {
        $doi = '10.5880/test.institutional.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Institutional Dataset - Organization as Creator');

        $institution = $this->findOrCreateInstitution(
            'GFZ German Research Centre for Geosciences',
            'https://ror.org/04z8jg394'
        );

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'position' => 1,
        ]);

        $this->addDescription($resource, 'This dataset was created by the institution as a whole, not attributed to individual researchers.', $this->abstractType);
        $this->createLandingPage($resource, 'institutional-creator');
        $this->logCreated('Resource with institutional creator');
    }

    /**
     * Scenario 6: Resource with mixed creators (persons and institutions)
     */
    private function createResourceWithMixedCreators(): void
    {
        $doi = '10.5880/test.mixed-creators.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Mixed Creators Dataset - Persons and Institutions');

        // Person creator
        $person = $this->findOrCreatePerson('Michael', 'Brown');
        $this->addCreator($resource, $person, 1);

        // Institution creator
        $institution = $this->findOrCreateInstitution('Helmholtz Association', 'https://ror.org/0281dp749');

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'position' => 2,
        ]);

        // Another person
        $person2 = $this->findOrCreatePerson('Lisa', 'White');
        $this->addCreator($resource, $person2, 3);

        $this->addDescription($resource, 'Joint research by individual researchers and institutional partners.', $this->abstractType);
        $this->createLandingPage($resource, 'mixed-creators');
        $this->logCreated('Resource with mixed creators (persons + institutions)');
    }

    /**
     * Scenario 7: Resource with many persistent identifiers (ORCID, ROR)
     */
    private function createResourceWithPersistentIdentifiers(): void
    {
        $doi = '10.5880/test.identifiers.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Rich Identifiers Dataset - ORCID and ROR');

        $authors = [
            ['Anna', 'Müller', '0000-0001-1111-1111', 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394'],
            ['Peter', 'Schneider', '0000-0002-2222-2222', 'University of Potsdam', 'https://ror.org/03bnmw459'],
            ['Julia', 'Wagner', '0000-0003-3333-3333', 'Helmholtz Centre Potsdam', 'https://ror.org/04z8jg394'],
        ];

        foreach ($authors as $index => $authorData) {
            $person = $this->findOrCreatePerson($authorData[0], $authorData[1], $authorData[2]);
            $creatorRecord = $this->addCreator($resource, $person, $index + 1);
            $this->addAffiliation($creatorRecord, $authorData[3], $authorData[4], 'ROR');
        }

        $this->addDescription($resource, 'All creators have ORCID identifiers and affiliations with ROR IDs.', $this->abstractType);
        $this->createLandingPage($resource, 'persistent-identifiers');
        $this->logCreated('Resource with persistent identifiers (ORCID, ROR)');
    }

    /**
     * Scenario 8: Resource with single license
     */
    private function createResourceWithSingleLicense(): void
    {
        $doi = '10.5880/test.single-license.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Single License Dataset - CC-BY-4.0');

        $person = $this->findOrCreatePerson('David', 'Martin');
        $this->addCreator($resource, $person, 1);

        $license = $this->findOrCreateLicense('CC-BY-4.0', 'Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/');
        $resource->rights()->attach($license->id);

        $this->addDescription($resource, 'Standard open access dataset with CC-BY-4.0 license.', $this->abstractType);
        $this->createLandingPage($resource, 'single-license');
        $this->logCreated('Resource with single license (CC-BY-4.0)');
    }

    /**
     * Scenario 9: Resource with multiple licenses
     */
    private function createResourceWithMultipleLicenses(): void
    {
        $doi = '10.5880/test.multi-license.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Multiple Licenses Dataset - Data + Code');

        $person = $this->findOrCreatePerson('Emily', 'Taylor');
        $this->addCreator($resource, $person, 1);

        $ccBy = $this->findOrCreateLicense('CC-BY-4.0', 'Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/');
        $mit = $this->findOrCreateLicense('MIT', 'MIT License', 'https://opensource.org/licenses/MIT');

        $resource->rights()->attach([$ccBy->id, $mit->id]);

        $this->addDescription($resource, 'Dataset containing both research data (CC-BY-4.0) and analysis code (MIT).', $this->abstractType);
        $this->createLandingPage($resource, 'multiple-licenses');
        $this->logCreated('Resource with multiple licenses (CC-BY-4.0 + MIT)');
    }

    /**
     * Scenario 10: Resource with contact persons
     */
    private function createResourceWithContactPersons(): void
    {
        $doi = '10.5880/test.contacts.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Contact Persons Dataset - Multiple Contacts');

        // Main creator
        $creator = $this->findOrCreatePerson('Robert', 'Green');
        $this->addCreator($resource, $creator, 1);

        // Contact persons as contributors
        $contact1 = $this->findOrCreatePerson('Sandra', 'Wilson', '0000-0001-5555-5555');
        $contributor1 = $this->addContributor($resource, $contact1, $this->contactPersonType, 1);
        $contributor1->update(['email' => 'sandra.wilson@gfz-potsdam.de']);
        $this->addAffiliation($contributor1, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');

        $contact2 = $this->findOrCreatePerson('Frank', 'Moore');
        $contributor2 = $this->addContributor($resource, $contact2, $this->contactPersonType, 2);
        $contributor2->update(['email' => 'frank.moore@gfz-potsdam.de']);

        $this->addDescription($resource, 'Dataset with designated contact persons for inquiries.', $this->abstractType);
        $this->createLandingPage($resource, 'contact-persons');
        $this->logCreated('Resource with contact persons');
    }

    /**
     * Scenario 11: Resource with GeoLocation point
     */
    private function createResourceWithGeoLocationPoint(): void
    {
        $doi = '10.5880/test.geo-point.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'GeoLocation Point - GFZ Potsdam');

        $person = $this->findOrCreatePerson('Gerd', 'Hoffmann');
        $this->addCreator($resource, $person, 1);

        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'GFZ German Research Centre for Geosciences, Potsdam',
            'point_longitude' => 13.0661,
            'point_latitude' => 52.3806,
        ]);

        $this->addDescription($resource, 'Measurement data from a single location at GFZ Potsdam.', $this->abstractType);
        $this->createLandingPage($resource, 'geo-point');
        $this->logCreated('Resource with GeoLocation point');
    }

    /**
     * Scenario 12: Resource with GeoLocation bounding box
     */
    private function createResourceWithGeoLocationBox(): void
    {
        $doi = '10.5880/test.geo-box.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'GeoLocation Bounding Box - Germany');

        $person = $this->findOrCreatePerson('Helga', 'Richter');
        $this->addCreator($resource, $person, 1);

        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Germany',
            'west_bound_longitude' => 5.87,
            'east_bound_longitude' => 15.04,
            'south_bound_latitude' => 47.27,
            'north_bound_latitude' => 55.06,
        ]);

        $this->addDescription($resource, 'National survey data covering all of Germany.', $this->abstractType);
        $this->createLandingPage($resource, 'geo-box');
        $this->logCreated('Resource with GeoLocation bounding box');
    }

    /**
     * Scenario 13: Resource with GeoLocation polygon
     */
    private function createResourceWithGeoLocationPolygon(): void
    {
        $doi = '10.5880/test.geo-polygon.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'GeoLocation Polygon - Lake Constance');

        $person = $this->findOrCreatePerson('Werner', 'Bauer');
        $this->addCreator($resource, $person, 1);

        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Lake Constance (Bodensee)',
            'polygon_points' => [
                ['longitude' => 9.1893, 'latitude' => 47.6631],
                ['longitude' => 9.3667, 'latitude' => 47.5000],
                ['longitude' => 9.6333, 'latitude' => 47.5000],
                ['longitude' => 9.7000, 'latitude' => 47.5333],
                ['longitude' => 9.5000, 'latitude' => 47.6333],
                ['longitude' => 9.1893, 'latitude' => 47.6631],
            ],
            'in_polygon_point_longitude' => 9.4,
            'in_polygon_point_latitude' => 47.55,
        ]);

        $this->addDescription($resource, 'Limnological study of Lake Constance.', $this->abstractType);
        $this->createLandingPage($resource, 'geo-polygon');
        $this->logCreated('Resource with GeoLocation polygon');
    }

    /**
     * Scenario 14: Resource with multiple GeoLocations (mixed types)
     */
    private function createResourceWithMultipleGeoLocations(): void
    {
        $doi = '10.5880/test.geo-multi.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Multiple GeoLocations - Mixed Types');

        $person = $this->findOrCreatePerson('Karin', 'Wolf');
        $this->addCreator($resource, $person, 1);

        // Point
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Berlin',
            'point_longitude' => 13.4050,
            'point_latitude' => 52.5200,
        ]);

        // Bounding box
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Bavaria',
            'west_bound_longitude' => 8.97,
            'east_bound_longitude' => 13.84,
            'south_bound_latitude' => 47.27,
            'north_bound_latitude' => 50.56,
        ]);

        // Another point
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Munich',
            'point_longitude' => 11.5820,
            'point_latitude' => 48.1351,
        ]);

        $this->addDescription($resource, 'Multi-site study across Germany.', $this->abstractType);
        $this->createLandingPage($resource, 'geo-multi');
        $this->logCreated('Resource with multiple GeoLocations (mixed)');
    }

    /**
     * Scenario 15: Resource with funding references
     */
    private function createResourceWithFundingReferences(): void
    {
        $doi = '10.5880/test.funding.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Funded Research Dataset - Multiple Funders');

        $person = $this->findOrCreatePerson('Andreas', 'Lehmann');
        $this->addCreator($resource, $person, 1);

        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type_id' => $this->rorFunderType->id,
            'award_number' => 'DFG-2024-001',
            'award_title' => 'Climate Research Initiative',
        ]);

        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'European Commission',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'funder_identifier_type_id' => $this->crossrefFunderType->id,
            'award_number' => 'H2020-12345',
            'award_uri' => 'https://cordis.europa.eu/project/id/12345',
            'award_title' => 'Horizon 2020 Earth Observation',
        ]);

        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Helmholtz Association',
            'funder_identifier' => 'https://ror.org/0281dp749',
            'funder_identifier_type_id' => $this->rorFunderType->id,
        ]);

        $this->addDescription($resource, 'Research funded by multiple national and international agencies.', $this->abstractType);
        $this->createLandingPage($resource, 'funding-refs');
        $this->logCreated('Resource with funding references');
    }

    /**
     * Scenario 16: Resource with related identifiers
     */
    private function createResourceWithRelatedIdentifiers(): void
    {
        $doi = '10.5880/test.related.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Related Identifiers Dataset - Citations and Supplements');

        $person = $this->findOrCreatePerson('Birgit', 'Schäfer');
        $this->addCreator($resource, $person, 1);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1000/science-paper-001',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->isSupplementToType->id,
            'position' => 1,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.1000/previous-dataset',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 2,
        ]);

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => 'https://github.com/example/analysis-code',
            'identifier_type_id' => $this->urlType->id,
            'relation_type_id' => $this->isSupplementToType->id,
            'position' => 3,
        ]);

        $this->addDescription($resource, 'Dataset with links to related publications and code.', $this->abstractType);
        $this->createLandingPage($resource, 'related-ids');
        $this->logCreated('Resource with related identifiers');
    }

    /**
     * Scenario 17: Resource with controlled vocabulary subjects (GCMD)
     */
    private function createResourceWithControlledSubjects(): void
    {
        $doi = '10.5880/test.gcmd.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'GCMD Keywords Dataset - Controlled Vocabulary');

        $person = $this->findOrCreatePerson('Christian', 'Bergmann');
        $this->addCreator($resource, $person, 1);

        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > ATMOSPHERE > ATMOSPHERIC CHEMISTRY > OXYGEN COMPOUNDS > OZONE',
            'subject_scheme' => 'GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/ozone-001',
        ]);

        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > CLIMATE INDICATORS > ATMOSPHERIC/OCEAN INDICATORS',
            'subject_scheme' => 'GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/climate-001',
        ]);

        $this->addDescription($resource, 'Atmospheric research data with GCMD controlled vocabulary keywords.', $this->abstractType);
        $this->createLandingPage($resource, 'gcmd-keywords');
        $this->logCreated('Resource with GCMD keywords');
    }

    /**
     * Scenario 18: Resource with free-text keywords only
     */
    private function createResourceWithFreeTextKeywords(): void
    {
        $doi = '10.5880/test.freetext.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Free-Text Keywords Dataset');

        $person = $this->findOrCreatePerson('Daniela', 'Krause');
        $this->addCreator($resource, $person, 1);

        $keywords = ['seismology', 'earthquake', 'monitoring', 'real-time data', 'Germany'];
        foreach ($keywords as $keyword) {
            Subject::create([
                'resource_id' => $resource->id,
                'value' => $keyword,
                'language' => 'en',
            ]);
        }

        $this->addDescription($resource, 'Dataset with user-defined free-text keywords.', $this->abstractType);
        $this->createLandingPage($resource, 'freetext-keywords');
        $this->logCreated('Resource with free-text keywords');
    }

    /**
     * Scenario 19: Resource with mixed subjects (controlled + free-text)
     */
    private function createResourceWithMixedSubjects(): void
    {
        $doi = '10.5880/test.mixed-subjects.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Mixed Subjects Dataset - GCMD and Free-Text');

        $person = $this->findOrCreatePerson('Eva', 'Hartmann');
        $this->addCreator($resource, $person, 1);

        // GCMD keyword
        Subject::create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS > IGNEOUS ROCKS',
            'subject_scheme' => 'GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        ]);

        // Free-text keywords
        Subject::create(['resource_id' => $resource->id, 'value' => 'petrology']);
        Subject::create(['resource_id' => $resource->id, 'value' => 'geochemistry']);
        Subject::create(['resource_id' => $resource->id, 'value' => 'volcanic rocks']);

        $this->addDescription($resource, 'Petrological study combining controlled and free-text keywords.', $this->abstractType);
        $this->createLandingPage($resource, 'mixed-subjects');
        $this->logCreated('Resource with mixed subjects');
    }

    /**
     * Scenario 20: Resource with temporal coverage
     */
    private function createResourceWithTemporalCoverage(): void
    {
        $doi = '10.5880/test.temporal.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Temporal Coverage Dataset - Date Ranges');

        $person = $this->findOrCreatePerson('Felix', 'Jung');
        $this->addCreator($resource, $person, 1);

        ResourceDate::create([
            'resource_id' => $resource->id,
            'start_date' => '2020-06-15',
            'end_date' => '2023-09-30',
            'date_type_id' => $this->collectedType->id,
            'date_information' => 'Data collection period',
        ]);

        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_value' => '2024-01-15',
            'date_type_id' => $this->createdType->id,
        ]);

        $this->addDescription($resource, 'Long-term monitoring data with defined temporal coverage.', $this->abstractType);
        $this->createLandingPage($resource, 'temporal-coverage');
        $this->logCreated('Resource with temporal coverage');
    }

    /**
     * Scenario 21: Resource with multiple titles (main, subtitle, alternative)
     */
    private function createResourceWithMultipleTitles(): void
    {
        $doi = '10.5880/test.multi-title.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Primary Research Title');

        $person = $this->findOrCreatePerson('Georg', 'Neumann');
        $this->addCreator($resource, $person, 1);

        $this->addTitle($resource, 'A Detailed Subtitle Explaining the Research Scope', $this->subtitleType);
        $this->addTitle($resource, 'Forschungsdatensatz Primärtitel', $this->alternativeTitleType, 'de');
        $this->addTitle($resource, 'Titre de Recherche Principal', $this->alternativeTitleType, 'fr');

        $this->addDescription($resource, 'Multilingual dataset with multiple title variants.', $this->abstractType);
        $this->createLandingPage($resource, 'multi-title');
        $this->logCreated('Resource with multiple titles');
    }

    /**
     * Scenario 22: Resource with multiple descriptions
     */
    private function createResourceWithMultipleDescriptions(): void
    {
        $doi = '10.5880/test.multi-desc.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Multiple Descriptions Dataset');

        $person = $this->findOrCreatePerson('Hannah', 'Otto');
        $this->addCreator($resource, $person, 1);

        $this->addDescription($resource, 'This dataset contains comprehensive measurements from a field study conducted in 2023. The data includes various geophysical parameters collected using state-of-the-art instrumentation.', $this->abstractType);
        $this->addDescription($resource, 'Field measurements were taken at 30-minute intervals using calibrated sensors. Data quality control included outlier detection and gap filling procedures.', $this->methodsType);

        $technicalInfo = DescriptionType::firstOrCreate(
            ['slug' => 'TechnicalInfo'],
            ['name' => 'Technical Info', 'slug' => 'TechnicalInfo', 'is_active' => true]
        );
        $this->addDescription($resource, 'Data format: NetCDF-4. Coordinate reference system: WGS84. Temporal resolution: 30 minutes.', $technicalInfo);

        $this->createLandingPage($resource, 'multi-desc');
        $this->logCreated('Resource with multiple descriptions');
    }

    /**
     * Scenario 23: Resource with sizes and formats
     */
    private function createResourceWithSizesAndFormats(): void
    {
        $doi = '10.5880/test.sizes-formats.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Large Dataset with Multiple Formats');

        $person = $this->findOrCreatePerson('Ingrid', 'Peters');
        $this->addCreator($resource, $person, 1);

        Size::create(['resource_id' => $resource->id, 'value' => '15.7 GB']);
        Size::create(['resource_id' => $resource->id, 'value' => '2,345 files']);
        Size::create(['resource_id' => $resource->id, 'value' => '120 hours of recording']);

        Format::create(['resource_id' => $resource->id, 'value' => 'application/x-netcdf']);
        Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
        Format::create(['resource_id' => $resource->id, 'value' => 'application/zip']);
        Format::create(['resource_id' => $resource->id, 'value' => 'image/tiff']);

        $this->addDescription($resource, 'Large dataset with multiple file formats and extensive metadata.', $this->abstractType);
        $this->createLandingPage($resource, 'sizes-formats');
        $this->logCreated('Resource with sizes and formats');
    }

    /**
     * Scenario 24: Resource without DOI (in curation status)
     */
    private function createResourceWithoutDoi(): void
    {
        // Check by title since there's no DOI
        $existingTitle = Title::where('value', 'Draft Resource - Awaiting DOI Registration')->first();
        if ($existingTitle) {
            $this->skippedCount++;

            return;
        }

        $resource = Resource::create([
            'doi' => null,
            'publication_year' => now()->year,
            'resource_type_id' => $this->resourceType->id,
            'language_id' => $this->language->id,
            'publisher_id' => $this->publisher->id,
            'version' => '1.0',
        ]);

        $this->addTitle($resource, 'Draft Resource - Awaiting DOI Registration', $this->mainTitleType);

        $person = $this->findOrCreatePerson('Jan', 'Vogel');
        $this->addCreator($resource, $person, 1);

        $this->addDescription($resource, 'This resource is still in curation and has not yet received a DOI.', $this->abstractType);
        $this->createLandingPage($resource, 'no-doi-draft');
        $this->logCreated('Resource without DOI (curation status)');
    }

    /**
     * Scenario 25: Resource with extensive affiliations
     */
    private function createResourceWithAffiliations(): void
    {
        $doi = '10.5880/test.affiliations.001';
        if ($this->resourceExists($doi)) {
            return;
        }

        $resource = $this->createBaseResource($doi, 'Multiple Affiliations Dataset');

        $person = $this->findOrCreatePerson('Karl', 'Zimmermann', '0000-0001-9999-9999');
        $creatorRecord = $this->addCreator($resource, $person, 1);

        // Multiple affiliations for one creator
        $this->addAffiliation($creatorRecord, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');
        $this->addAffiliation($creatorRecord, 'University of Potsdam', 'https://ror.org/03bnmw459', 'ROR');
        $this->addAffiliation($creatorRecord, 'Helmholtz Association', 'https://ror.org/0281dp749', 'ROR');

        $person2 = $this->findOrCreatePerson('Lena', 'Braun', '0000-0002-8888-8888');
        $creatorRecord2 = $this->addCreator($resource, $person2, 2);
        $this->addAffiliation($creatorRecord2, 'Max Planck Institute for Meteorology', 'https://ror.org/01hhn8329', 'ROR');
        $this->addAffiliation($creatorRecord2, 'University of Hamburg', 'https://ror.org/00g30e956', 'ROR');

        $this->addDescription($resource, 'Collaborative research with researchers having multiple institutional affiliations.', $this->abstractType);
        $this->createLandingPage($resource, 'affiliations');
        $this->logCreated('Resource with multiple affiliations');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function resourceExists(?string $doi): bool
    {
        if ($doi === null) {
            return false;
        }

        $exists = Resource::where('doi', $doi)->exists();
        if ($exists) {
            $this->skippedCount++;
        }

        return $exists;
    }

    private function createBaseResource(string $doi, string $title, string $version = '1.0'): Resource
    {
        $resource = Resource::create([
            'doi' => $doi,
            'publication_year' => now()->year,
            'resource_type_id' => $this->resourceType->id,
            'language_id' => $this->language->id,
            'publisher_id' => $this->publisher->id,
            'version' => $version,
        ]);

        $this->addTitle($resource, $title, $this->mainTitleType);

        return $resource;
    }

    private function findOrCreatePerson(string $givenName, string $familyName, ?string $orcid = null): Person
    {
        // If ORCID is provided, search by ORCID first (unique constraint)
        if ($orcid) {
            $existing = Person::where('name_identifier', $orcid)->first();
            if ($existing) {
                return $existing;
            }

            return Person::create([
                'given_name' => $givenName,
                'family_name' => $familyName,
                'name_identifier' => $orcid,
                'name_identifier_scheme' => 'ORCID',
                'scheme_uri' => 'https://orcid.org/',
            ]);
        }

        // Without ORCID, search by name
        return Person::firstOrCreate(
            [
                'given_name' => $givenName,
                'family_name' => $familyName,
            ],
            [
                'name_identifier' => null,
                'name_identifier_scheme' => null,
                'scheme_uri' => null,
            ]
        );
    }

    private function findOrCreateInstitution(string $name, ?string $ror = null): Institution
    {
        return Institution::firstOrCreate(
            ['name' => $name],
            [
                'name_identifier' => $ror,
                'name_identifier_scheme' => $ror ? 'ROR' : null,
                'scheme_uri' => $ror ? 'https://ror.org/' : null,
            ]
        );
    }

    private function findOrCreateLicense(string $identifier, string $name, string $uri): Right
    {
        return Right::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => $name, 'uri' => $uri, 'is_active' => true]
        );
    }

    private function addCreator(
        Resource $resource,
        Person $person,
        int $position,
        ?string $email = null,
        ?string $website = null
    ): ResourceCreator {
        return ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => $position,
            'email' => $email,
            'website' => $website,
        ]);
    }

    private function addContributor(
        Resource $resource,
        Person $person,
        ContributorType $type,
        int $position
    ): ResourceContributor {
        return ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'contributor_type_id' => $type->id,
            'position' => $position,
        ]);
    }

    private function addAffiliation(
        ResourceCreator|ResourceContributor $record,
        string $name,
        ?string $identifier = null,
        ?string $scheme = null
    ): Affiliation {
        return Affiliation::create([
            'affiliatable_type' => $record::class,
            'affiliatable_id' => $record->id,
            'name' => $name,
            'identifier' => $identifier,
            'identifier_scheme' => $scheme,
            'scheme_uri' => $scheme === 'ROR' ? 'https://ror.org/' : null,
        ]);
    }

    private function addTitle(Resource $resource, string $value, TitleType $type, string $language = 'en'): Title
    {
        return Title::create([
            'resource_id' => $resource->id,
            'value' => $value,
            'title_type_id' => $type->id,
            'language' => $language,
        ]);
    }

    private function addDescription(Resource $resource, string $value, DescriptionType $type, string $language = 'en'): Description
    {
        return Description::create([
            'resource_id' => $resource->id,
            'value' => $value,
            'description_type_id' => $type->id,
            'language' => $language,
        ]);
    }

    private function createLandingPage(Resource $resource, string $slugSuffix): LandingPage
    {
        return LandingPage::create([
            'resource_id' => $resource->id,
            'slug' => 'test-'.$slugSuffix,
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now(),
            'preview_token' => bin2hex(random_bytes(32)),
        ]);
    }

    private function logCreated(string $description): void
    {
        $this->createdCount++;
        $this->command->info("  ✓ {$this->createdCount}. {$description}");
    }
}
