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
 * Comprehensive test data seeder for Resources.
 *
 * Creates resources in various configurations to cover different testing scenarios:
 * - Mandatory fields only
 * - Fully populated resources
 * - Resources with many persistent identifiers (ORCID, ROR)
 * - Resources with single/multiple licenses
 * - Resources with many authors/contributors
 * - Resources with various geo-location types
 * - Resources with funding references
 * - Resources with related identifiers
 * - And more...
 *
 * Each resource has a descriptive main title indicating its test purpose.
 *
 * Usage: php artisan db:seed --class=ResourceTestDataSeeder
 *
 * DEVELOPMENT ONLY - Do not run in production!
 */
class ResourceTestDataSeeder extends Seeder
{
    private ResourceType $resourceType;

    private Language $language;

    private Publisher $publisher;

    private TitleType $mainTitleType;

    private TitleType $subtitleType;

    private TitleType $alternativeTitleType;

    private DescriptionType $abstractType;

    private DescriptionType $methodsType;

    private DateType $collectedType;

    private DateType $createdType;

    private IdentifierType $doiType;

    private IdentifierType $urlType;

    private RelationType $citesType;

    private RelationType $isSupplementToType;

    private ContributorType $dataCollectorType;

    private ContributorType $projectLeaderType;

    private FunderIdentifierType $rorFunderType;

    private Right $ccByLicense;

    /** @var array<int, int> */
    private array $createdResourceIds = [];

    public function run(): void
    {
        // Prevent running in production environment
        if (App::environment('production')) {
            $this->command->error('This seeder cannot be run in production environment!');

            return;
        }

        // Idempotency check: If test resources already exist, skip seeding
        // We check for a characteristic landing page slug that indicates the seeder has run before.
        if (LandingPage::where('slug', 'mandatory-fields-only')->exists()) {
            $this->command->info('ResourceTestDataSeeder: Test resources already exist, skipping...');

            return;
        }

        $this->command->info('Creating comprehensive test resources...');
        $this->command->newLine();

        $this->initializeLookupTables();

        // Create all test scenarios
        $this->createMandatoryFieldsOnly();
        $this->createFullyPopulatedResource();
        $this->createManyCreatorsWithOrcids();
        $this->createCreatorsWithoutOrcids();
        $this->createMixedCreatorsWithAndWithoutOrcids();
        $this->createManyContributors();
        $this->createContributorsWithRor();
        $this->createInstitutionalCreators();
        $this->createSingleLicense();
        $this->createMultipleLicenses();
        $this->createManyKeywords();
        $this->createControlledVocabularyKeywords();
        $this->createManyGeoLocationsPoints();
        $this->createGeoLocationsBoundingBoxes();
        $this->createGeoLocationsPolygons();
        $this->createMixedGeoLocations();
        $this->createNoGeoLocations();
        $this->createManyRelatedIdentifiers();
        $this->createManyFundingReferences();
        $this->createMultipleTitles();
        $this->createMultipleDescriptions();
        $this->createManyDates();
        $this->createContactPersons();
        $this->createSizesAndFormats();

        // Files Section test scenarios (for Issue #373)
        $this->createFilesWithDownloadUrl();
        $this->createFilesWithContactPersonOnly();
        $this->createFilesWithNoContactOptions();

        $this->command->newLine();
        $this->command->info('✓ Created '.count($this->createdResourceIds).' test resources.');
        $this->command->newLine();

        // Output summary table
        $this->outputSummary();
    }

    /**
     * Initialize all required lookup table entries.
     */
    private function initializeLookupTables(): void
    {
        $this->resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        $this->language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]
        );

        $this->publisher = Publisher::firstOrCreate(
            ['name' => 'GFZ Data Services'],
            ['name' => 'GFZ Data Services', 'is_default' => true]
        );

        $this->mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle']
        );

        $this->subtitleType = TitleType::firstOrCreate(
            ['slug' => 'Subtitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle']
        );

        $this->alternativeTitleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle']
        );

        $this->abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract']
        );

        $this->methodsType = DescriptionType::firstOrCreate(
            ['slug' => 'Methods'],
            ['name' => 'Methods', 'slug' => 'Methods']
        );

        $this->collectedType = DateType::firstOrCreate(
            ['slug' => 'Collected'],
            ['name' => 'Collected', 'slug' => 'Collected']
        );

        $this->createdType = DateType::firstOrCreate(
            ['slug' => 'Created'],
            ['name' => 'Created', 'slug' => 'Created']
        );

        $this->doiType = IdentifierType::firstOrCreate(
            ['slug' => 'DOI'],
            ['name' => 'DOI', 'slug' => 'DOI']
        );

        $this->urlType = IdentifierType::firstOrCreate(
            ['slug' => 'URL'],
            ['name' => 'URL', 'slug' => 'URL']
        );

        $this->citesType = RelationType::firstOrCreate(
            ['slug' => 'Cites'],
            ['name' => 'Cites', 'slug' => 'Cites']
        );

        $this->isSupplementToType = RelationType::firstOrCreate(
            ['slug' => 'IsSupplementTo'],
            ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo']
        );

        $this->dataCollectorType = ContributorType::firstOrCreate(
            ['slug' => 'DataCollector'],
            ['name' => 'Data Collector', 'slug' => 'DataCollector']
        );

        $this->projectLeaderType = ContributorType::firstOrCreate(
            ['slug' => 'ProjectLeader'],
            ['name' => 'Project Leader', 'slug' => 'ProjectLeader']
        );

        $this->rorFunderType = FunderIdentifierType::firstOrCreate(
            ['slug' => 'ROR'],
            ['name' => 'ROR', 'slug' => 'ROR']
        );

        // Get CC-BY-4.0 license for mandatory license tests
        // Note: Requires RightsSeeder to have been run first (via DatabaseSeeder)
        $this->ccByLicense = Right::where('identifier', 'CC-BY-4.0')->firstOr(
            fn () => throw new \RuntimeException(
                'CC-BY-4.0 license not found. Please run DatabaseSeeder first to populate the Rights table: '
                .'php artisan db:seed --class=DatabaseSeeder'
            )
        );
    }

    // =========================================================================
    // Test Scenarios
    // =========================================================================

    /**
     * Scenario: Resource with only mandatory DataCite fields.
     */
    private function createMandatoryFieldsOnly(): void
    {
        // Use addDefaultContact: false since we add our own specific contact person below
        $resource = $this->createBaseResource(
            'TEST: Mandatory Fields Only',
            null,  // version
            'This is a minimal test resource containing only the mandatory fields required by the DataCite schema and ERNIE metadata editor. It demonstrates the baseline requirements for publishing research data.',
            false  // addDefaultContact - we add our own below
        );

        // Creator with contact person (mandatory: at least one creator as contact with email)
        $this->addCreator(
            $resource,
            'Jane',
            'Doe',
            null,
            1,
            true,  // isContact
            'jane.doe@example.com'  // email (required for contact person)
        );

        // Note: License is already added by createBaseResource (CC-BY-4.0)

        $this->createLandingPage($resource, 'mandatory-fields-only');

        $this->logCreation($resource, 'Mandatory fields only');
    }

    /**
     * Scenario: Fully populated resource with all possible fields.
     */
    private function createFullyPopulatedResource(): void
    {
        $resource = $this->createBaseResource(
            'TEST: Fully Populated Resource with All Fields',
            '2.1.0',  // version
            'This dataset contains comprehensive test data for validating the metadata editor functionality. It includes various types of metadata fields following the DataCite 4.6 schema.'
        );

        // Note: Default contact person is already added by createBaseResource at position 1

        // Main creator with ORCID and affiliation (position 2)
        $creator = $this->addCreator($resource, 'Alice', 'Wonderland', '0000-0001-1234-5678', 2);
        $this->addAffiliation($creator, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');

        // Second creator (position 3)
        $creator2 = $this->addCreator($resource, 'Bob', 'Builder', '0000-0002-2345-6789', 3);
        $this->addAffiliation($creator2, 'University of Potsdam', 'https://ror.org/03bnmw459', 'ROR');

        // Contributor
        $contributor = $this->addContributor($resource, 'Charlie', 'Chaplin', $this->dataCollectorType, null, 1);
        $this->addAffiliation($contributor, 'Max Planck Society');

        // Titles
        Title::create([
            'resource_id' => $resource->id,
            'value' => 'A Comprehensive Dataset for Testing',
            'title_type_id' => $this->subtitleType->id,
        ]);

        // Additional description (Methods) - Abstract is already created by createBaseResource
        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Data was collected using standardized protocols and processed using Python scripts.',
            'description_type_id' => $this->methodsType->id,
        ]);

        // Subjects
        Subject::create(['resource_id' => $resource->id, 'value' => 'Geosciences']);
        Subject::create(['resource_id' => $resource->id, 'value' => 'Test Data']);
        Subject::create(['resource_id' => $resource->id, 'value' => 'Metadata']);

        // Dates
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_value' => '2024-01-15',
            'date_type_id' => $this->createdType->id,
        ]);

        ResourceDate::create([
            'resource_id' => $resource->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-30',
            'date_type_id' => $this->collectedType->id,
        ]);

        // GeoLocation
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Potsdam, Germany',
            'point_longitude' => 13.0661,
            'point_latitude' => 52.3806,
        ]);

        // Related Identifier - using real DOI for citation testing
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/igets.su.l1.001',
            'identifier_type_id' => $this->doiType->id,
            'relation_type_id' => $this->citesType->id,
            'position' => 1,
        ]);

        // Funding
        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type_id' => $this->rorFunderType->id,
            'award_number' => 'DFG-123456',
            'award_title' => 'Research Project XYZ',
        ]);

        // Note: CC-BY-4.0 license is already added by createBaseResource

        // Sizes and Formats
        Size::create(['resource_id' => $resource->id, 'value' => '1.5 GB']);
        Format::create(['resource_id' => $resource->id, 'value' => 'application/zip']);

        $this->createLandingPage($resource, 'fully-populated');

        $this->logCreation($resource, 'Fully populated with all fields');
    }

    /**
     * Scenario: Many creators all with ORCID identifiers.
     */
    private function createManyCreatorsWithOrcids(): void
    {
        $resource = $this->createBaseResource('TEST: Many Creators All with ORCID');

        // Note: Default contact person is already at position 1
        $creators = [
            ['Anna', 'Schmidt', '0000-0001-1111-1111'],
            ['Bruno', 'Meyer', '0000-0001-2222-2222'],
            ['Clara', 'Weber', '0000-0001-3333-3333'],
            ['David', 'Fischer', '0000-0001-4444-4444'],
            ['Emma', 'Wagner', '0000-0001-5555-5555'],
            ['Felix', 'Becker', '0000-0001-6666-6666'],
            ['Greta', 'Hoffmann', '0000-0001-7777-7777'],
            ['Hans', 'Schäfer', '0000-0001-8888-8888'],
        ];

        foreach ($creators as $index => $data) {
            $creator = $this->addCreator($resource, $data[0], $data[1], $data[2], $index + 2);
            $this->addAffiliation($creator, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');
        }

        $this->createLandingPage($resource, 'many-creators-all-with-orcid');

        $this->logCreation($resource, '8 creators all with ORCID');
    }

    /**
     * Scenario: Creators without any ORCID identifiers.
     */
    private function createCreatorsWithoutOrcids(): void
    {
        $resource = $this->createBaseResource('TEST: Creators Without ORCID');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Max', 'Mustermann', null, 2);
        $this->addCreator($resource, 'Erika', 'Musterfrau', null, 3);
        $this->addCreator($resource, 'John', 'Smith', null, 4);

        $this->createLandingPage($resource, 'creators-without-orcid');

        $this->logCreation($resource, '3 creators without ORCID');
    }

    /**
     * Scenario: Mixed creators - some with ORCID, some without.
     */
    private function createMixedCreatorsWithAndWithoutOrcids(): void
    {
        $resource = $this->createBaseResource('TEST: Mixed Creators With and Without ORCID');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Alice', 'With-Orcid', '0000-0002-1111-1111', 2);
        $this->addCreator($resource, 'Bob', 'Without-Orcid', null, 3);
        $this->addCreator($resource, 'Charlie', 'With-Orcid', '0000-0002-2222-2222', 4);
        $this->addCreator($resource, 'Diana', 'Without-Orcid', null, 5);
        $this->addCreator($resource, 'Eve', 'With-Orcid', '0000-0002-3333-3333', 6);

        $this->createLandingPage($resource, 'mixed-orcid-creators');

        $this->logCreation($resource, '5 creators - mixed ORCID status');
    }

    /**
     * Scenario: Many contributors with different types.
     */
    private function createManyContributors(): void
    {
        $resource = $this->createBaseResource('TEST: Many Contributors with Different Types');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Main', 'Author', null, 2);

        $contributorTypes = ContributorType::all();
        $position = 1;

        foreach ($contributorTypes->take(10) as $type) {
            $firstName = 'Contributor'.$position;
            $lastName = $type->name;
            $this->addContributor($resource, $firstName, $lastName, $type, null, $position);
            $position++;
        }

        $this->createLandingPage($resource, 'many-contributors');

        $this->logCreation($resource, '10 contributors with different types');
    }

    /**
     * Scenario: Contributors with ROR affiliations.
     */
    private function createContributorsWithRor(): void
    {
        $resource = $this->createBaseResource('TEST: Contributors with ROR Affiliations');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Main', 'Author', null, 2);

        $contributor1 = $this->addContributor($resource, 'Peter', 'Schmidt', $this->dataCollectorType, '0000-0003-1111-1111', 1);
        $this->addAffiliation($contributor1, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');
        $this->addAffiliation($contributor1, 'University of Potsdam', 'https://ror.org/03bnmw459', 'ROR');

        $contributor2 = $this->addContributor($resource, 'Maria', 'Müller', $this->projectLeaderType, '0000-0003-2222-2222', 2);
        $this->addAffiliation($contributor2, 'Helmholtz Association', 'https://ror.org/0281dp749', 'ROR');

        $this->createLandingPage($resource, 'contributors-with-ror');

        $this->logCreation($resource, 'Contributors with ROR affiliations');
    }

    /**
     * Scenario: Institutional creators (organizations as creators).
     */
    private function createInstitutionalCreators(): void
    {
        $resource = $this->createBaseResource('TEST: Institutional Creators (Organizations)');

        // Note: Default contact person is already at position 1

        // Personal creator at position 2
        $this->addCreator($resource, 'John', 'Coordinator', null, 2);

        // Institutional creators
        $institution1 = Institution::firstOrCreate(
            ['name' => 'GFZ German Research Centre for Geosciences'],
            [
                'name_identifier' => 'https://ror.org/04z8jg394',
                'name_identifier_scheme' => 'ROR',
                'scheme_uri' => 'https://ror.org/',
            ]
        );

        $institution2 = Institution::firstOrCreate(
            ['name' => 'European Space Agency'],
            [
                'name_identifier' => 'https://ror.org/03j7hsg31',
                'name_identifier_scheme' => 'ROR',
                'scheme_uri' => 'https://ror.org/',
            ]
        );

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution1->id,
            'position' => 3,
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution2->id,
            'position' => 4,
        ]);

        $this->createLandingPage($resource, 'institutional-creators');

        $this->logCreation($resource, 'Mix of personal and institutional creators');
    }

    /**
     * Scenario: Single license/rights.
     */
    private function createSingleLicense(): void
    {
        $resource = $this->createBaseResource('TEST: Single License (CC-BY-4.0)');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'License', 'Tester', null, 2);

        // Note: CC-BY-4.0 license is already added by createBaseResource

        $this->createLandingPage($resource, 'single-license');

        $this->logCreation($resource, 'Single CC-BY-4.0 license');
    }

    /**
     * Scenario: Multiple licenses/rights.
     */
    private function createMultipleLicenses(): void
    {
        $resource = $this->createBaseResource('TEST: Multiple Licenses');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Multi', 'License', null, 2);

        // Note: CC-BY-4.0 is already added by createBaseResource, add additional licenses
        $additionalLicenses = Right::whereIn('identifier', ['CC-BY-SA-4.0', 'CC0-1.0'])->get();
        foreach ($additionalLicenses as $license) {
            $resource->rights()->attach($license->id);
        }

        $this->createLandingPage($resource, 'multiple-licenses');

        // Count includes the CC-BY-4.0 already added
        $this->logCreation($resource, ($additionalLicenses->count() + 1).' licenses attached');
    }

    /**
     * Scenario: Many free-text keywords.
     */
    private function createManyKeywords(): void
    {
        $resource = $this->createBaseResource('TEST: Many Free-Text Keywords');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Keyword', 'Author', null, 2);

        $keywords = [
            'Geophysics', 'Seismology', 'Earthquake', 'Fault Zone', 'Tectonic Plates',
            'Magnitude', 'Epicenter', 'Ground Motion', 'Wave Propagation', 'Lithosphere',
            'Mantle', 'Core', 'Subduction', 'Volcanic Activity', 'Tsunami',
        ];

        foreach ($keywords as $keyword) {
            Subject::create([
                'resource_id' => $resource->id,
                'value' => $keyword,
            ]);
        }

        $this->createLandingPage($resource, 'many-keywords');

        $this->logCreation($resource, count($keywords).' free-text keywords');
    }

    /**
     * Scenario: Keywords from controlled vocabularies (GCMD).
     */
    private function createControlledVocabularyKeywords(): void
    {
        $resource = $this->createBaseResource('TEST: GCMD Controlled Vocabulary Keywords');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Vocabulary', 'Expert', null, 2);

        // GCMD Science Keywords
        $gcmdKeywords = [
            ['value' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY', 'scheme' => 'GCMD Science Keywords', 'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords'],
            ['value' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS', 'scheme' => 'GCMD Science Keywords', 'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords'],
            ['value' => 'EARTH SCIENCE > SOLID EARTH > GRAVITY/GRAVITATIONAL FIELD', 'scheme' => 'GCMD Science Keywords', 'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords'],
        ];

        foreach ($gcmdKeywords as $kw) {
            Subject::create([
                'resource_id' => $resource->id,
                'value' => $kw['value'],
                'subject_scheme' => $kw['scheme'],
                'scheme_uri' => $kw['uri'],
            ]);
        }

        // Also add some free keywords
        Subject::create(['resource_id' => $resource->id, 'value' => 'Additional free keyword']);

        $this->createLandingPage($resource, 'gcmd-keywords');

        $this->logCreation($resource, 'GCMD controlled vocabulary + free keywords');
    }

    /**
     * Scenario: Many geo-locations with point coordinates.
     */
    private function createManyGeoLocationsPoints(): void
    {
        $resource = $this->createBaseResource('TEST: Many GeoLocations - Points Only');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Geo', 'Point', null, 2);

        $points = [
            ['GFZ Potsdam', 13.0661, 52.3806],
            ['Berlin', 13.4050, 52.5200],
            ['Munich', 11.5820, 48.1351],
            ['Hamburg', 9.9937, 53.5511],
            ['Frankfurt', 8.6821, 50.1109],
            ['Cologne', 6.9603, 50.9375],
            ['Stuttgart', 9.1829, 48.7758],
            ['Dresden', 13.7373, 51.0504],
        ];

        foreach ($points as $point) {
            GeoLocation::create([
                'resource_id' => $resource->id,
                'place' => $point[0],
                'point_longitude' => $point[1],
                'point_latitude' => $point[2],
            ]);
        }

        $this->createLandingPage($resource, 'geo-points');

        $this->logCreation($resource, count($points).' point locations');
    }

    /**
     * Scenario: Geo-locations with bounding boxes.
     */
    private function createGeoLocationsBoundingBoxes(): void
    {
        $resource = $this->createBaseResource('TEST: GeoLocations - Bounding Boxes');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Geo', 'Box', null, 2);

        // Germany bounding box
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Germany',
            'west_bound_longitude' => 5.87,
            'east_bound_longitude' => 15.04,
            'south_bound_latitude' => 47.27,
            'north_bound_latitude' => 55.06,
        ]);

        // Bavaria bounding box
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Bavaria, Germany',
            'west_bound_longitude' => 8.97,
            'east_bound_longitude' => 13.84,
            'south_bound_latitude' => 47.27,
            'north_bound_latitude' => 50.56,
        ]);

        // Brandenburg bounding box
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Brandenburg, Germany',
            'west_bound_longitude' => 11.27,
            'east_bound_longitude' => 14.77,
            'south_bound_latitude' => 51.36,
            'north_bound_latitude' => 53.56,
        ]);

        $this->createLandingPage($resource, 'geo-bounding-boxes');

        $this->logCreation($resource, '3 bounding box locations');
    }

    /**
     * Scenario: Geo-locations with polygons.
     */
    private function createGeoLocationsPolygons(): void
    {
        $resource = $this->createBaseResource('TEST: GeoLocations - Polygons');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Geo', 'Polygon', null, 2);

        // Lake Constance polygon
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

        // Alps region polygon
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Alps Region',
            'polygon_points' => [
                ['longitude' => 10.0, 'latitude' => 47.5],
                ['longitude' => 12.0, 'latitude' => 47.0],
                ['longitude' => 14.0, 'latitude' => 47.5],
                ['longitude' => 12.0, 'latitude' => 48.0],
                ['longitude' => 10.0, 'latitude' => 47.5],
            ],
            'in_polygon_point_longitude' => 12.0,
            'in_polygon_point_latitude' => 47.5,
        ]);

        $this->createLandingPage($resource, 'geo-polygons');

        $this->logCreation($resource, '2 polygon locations');
    }

    /**
     * Scenario: Mixed geo-location types.
     */
    private function createMixedGeoLocations(): void
    {
        $resource = $this->createBaseResource('TEST: GeoLocations - Mixed Types (Points, Boxes, Polygons)');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Geo', 'Mix', null, 2);

        // Point
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Berlin City Center',
            'point_longitude' => 13.4050,
            'point_latitude' => 52.5200,
        ]);

        // Bounding Box
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Saxony, Germany',
            'west_bound_longitude' => 11.87,
            'east_bound_longitude' => 15.04,
            'south_bound_latitude' => 50.17,
            'north_bound_latitude' => 51.68,
        ]);

        // Polygon
        GeoLocation::create([
            'resource_id' => $resource->id,
            'place' => 'Triangular Research Area',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 52.0],
                ['longitude' => 13.5, 'latitude' => 52.5],
                ['longitude' => 13.0, 'latitude' => 52.0],
            ],
            'in_polygon_point_longitude' => 13.5,
            'in_polygon_point_latitude' => 52.2,
        ]);

        $this->createLandingPage($resource, 'geo-mixed');

        $this->logCreation($resource, 'Mixed geo-location types');
    }

    /**
     * Scenario: No geo-locations (control case).
     */
    private function createNoGeoLocations(): void
    {
        $resource = $this->createBaseResource(
            'TEST: No GeoLocations (Control Case)',
            null,  // version
            'This dataset intentionally has no geographic location data. The location section should not appear on the landing page.'
        );

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'No', 'Location', null, 2);

        $this->createLandingPage($resource, 'no-geo-locations');

        $this->logCreation($resource, 'No geo-locations');
    }

    /**
     * Scenario: Many related identifiers.
     */
    private function createManyRelatedIdentifiers(): void
    {
        $resource = $this->createBaseResource('TEST: Many Related Identifiers');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Relation', 'Expert', null, 2);

        // Using real DOIs for accurate landing page display
        $relatedDois = [
            ['10.5880/igets.su.l1.001', 'Cites'],
            ['10.1007/978-3-642-20338-1_37', 'IsSupplementTo'],
            ['10.1016/j.jog.2009.09.009', 'References'],
            ['10.1016/j.jog.2009.09.020', 'IsCitedBy'],
            ['10.1785/0120100217', 'IsPartOf'],
        ];

        foreach ($relatedDois as $index => $data) {
            $relationType = RelationType::where('slug', $data[1])->first();
            if ($relationType) {
                RelatedIdentifier::create([
                    'resource_id' => $resource->id,
                    'identifier' => $data[0],
                    'identifier_type_id' => $this->doiType->id,
                    'relation_type_id' => $relationType->id,
                    'position' => $index + 1,
                ]);
            }
        }

        // Also add a URL
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => 'https://example.org/documentation',
            'identifier_type_id' => $this->urlType->id,
            'relation_type_id' => $this->isSupplementToType->id,
            'position' => 6,
        ]);

        $this->createLandingPage($resource, 'many-related-identifiers');

        $this->logCreation($resource, '6 related identifiers');
    }

    /**
     * Scenario: Many funding references.
     */
    private function createManyFundingReferences(): void
    {
        $resource = $this->createBaseResource('TEST: Many Funding References');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Funding', 'Recipient', null, 2);

        $fundings = [
            ['Deutsche Forschungsgemeinschaft (DFG)', 'https://ror.org/018mejw64', 'DFG-123456', 'Climate Research Project'],
            ['Helmholtz Association', 'https://ror.org/0281dp749', 'HGF-789012', 'Data Science Initiative'],
            ['European Commission', 'https://ror.org/00k4n6c32', 'H2020-345678', 'Horizon 2020 Grant'],
            ['German Federal Ministry of Education and Research', 'https://ror.org/04pz7b180', 'BMBF-901234', 'National Research Programme'],
        ];

        foreach ($fundings as $funding) {
            FundingReference::create([
                'resource_id' => $resource->id,
                'funder_name' => $funding[0],
                'funder_identifier' => $funding[1],
                'funder_identifier_type_id' => $this->rorFunderType->id,
                'award_number' => $funding[2],
                'award_title' => $funding[3],
            ]);
        }

        $this->createLandingPage($resource, 'many-funding-references');

        $this->logCreation($resource, count($fundings).' funding references');
    }

    /**
     * Scenario: Multiple title types.
     */
    private function createMultipleTitles(): void
    {
        $resource = $this->createBaseResource('TEST: Multiple Title Types (Main, Subtitle, Alternative)');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Title', 'Author', null, 2);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'A Detailed Subtitle Explaining the Dataset',
            'title_type_id' => $this->subtitleType->id,
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Alternative Name for the Dataset',
            'title_type_id' => $this->alternativeTitleType->id,
        ]);

        Title::create([
            'resource_id' => $resource->id,
            'value' => 'Datensatz mit deutschem Alternativtitel',
            'title_type_id' => $this->alternativeTitleType->id,
        ]);

        $this->createLandingPage($resource, 'multiple-titles');

        $this->logCreation($resource, '4 titles (main + subtitle + 2 alternative)');
    }

    /**
     * Scenario: Multiple descriptions.
     */
    private function createMultipleDescriptions(): void
    {
        $resource = $this->createBaseResource(
            'TEST: Multiple Description Types',
            null,  // version
            'This is the main abstract describing the dataset and its scientific significance. It provides an overview of the data collection, analysis methods, and key findings.'
        );

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Description', 'Author', null, 2);

        // Additional descriptions (Methods, TechnicalInfo) - Abstract is already created by createBaseResource
        Description::create([
            'resource_id' => $resource->id,
            'value' => 'Data was collected using high-precision instruments following ISO standards. Processing involved quality control, outlier detection, and statistical analysis using R and Python.',
            'description_type_id' => $this->methodsType->id,
        ]);

        $technicalInfoType = DescriptionType::where('slug', 'TechnicalInfo')->first();
        if ($technicalInfoType) {
            Description::create([
                'resource_id' => $resource->id,
                'value' => 'File format: NetCDF 4.0. Coordinate reference system: WGS84. Temporal resolution: hourly. Spatial resolution: 1km.',
                'description_type_id' => $technicalInfoType->id,
            ]);
        }

        $this->createLandingPage($resource, 'multiple-descriptions');

        $this->logCreation($resource, '3 description types');
    }

    /**
     * Scenario: Many dates with different types.
     */
    private function createManyDates(): void
    {
        $resource = $this->createBaseResource('TEST: Many Date Types');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'Date', 'Expert', null, 2);

        $dateTypes = DateType::all();

        // Single dates
        $singleDates = [
            'Created' => '2024-01-01',
            'Submitted' => '2024-02-15',
            'Accepted' => '2024-03-01',
            'Available' => '2024-04-01',
            'Updated' => '2024-05-15',
        ];

        foreach ($singleDates as $typeName => $dateValue) {
            $type = $dateTypes->firstWhere('slug', $typeName);
            if ($type) {
                ResourceDate::create([
                    'resource_id' => $resource->id,
                    'date_value' => $dateValue,
                    'date_type_id' => $type->id,
                ]);
            }
        }

        // Date ranges
        $rangeDates = [
            'Collected' => ['2023-06-01', '2023-12-31'],
            'Valid' => ['2024-01-01', '2025-12-31'],
        ];

        foreach ($rangeDates as $typeName => $range) {
            $type = $dateTypes->firstWhere('slug', $typeName);
            if ($type) {
                ResourceDate::create([
                    'resource_id' => $resource->id,
                    'start_date' => $range[0],
                    'end_date' => $range[1],
                    'date_type_id' => $type->id,
                ]);
            }
        }

        $this->createLandingPage($resource, 'many-dates');

        $this->logCreation($resource, (count($singleDates) + count($rangeDates)).' date entries');
    }

    /**
     * Scenario: Contact persons with full details.
     */
    private function createContactPersons(): void
    {
        // Use addDefaultContact: false since we add our own specific contact persons below
        $resource = $this->createBaseResource(
            'TEST: Contact Persons with Full Details',
            null,
            null,
            false  // addDefaultContact - we add our own below
        );

        // Main author (not contact) at position 1
        $this->addCreator($resource, 'Main', 'Author', '0000-0004-1111-1111', 1);

        // Contact persons with email and website - using addCreator parameters directly
        $creator1 = $this->addCreator(
            $resource,
            'Anna',
            'Contact',
            '0000-0004-2222-2222',
            2,
            true,  // isContact
            'anna.contact@gfz-potsdam.de',
            'https://www.gfz-potsdam.de/staff/anna-contact'
        );
        $this->addAffiliation($creator1, 'GFZ German Research Centre for Geosciences', 'https://ror.org/04z8jg394', 'ROR');

        $creator2 = $this->addCreator(
            $resource,
            'Bruno',
            'Kontakt',
            '0000-0004-3333-3333',
            3,
            true,  // isContact
            'bruno.kontakt@uni-potsdam.de'
        );
        $this->addAffiliation($creator2, 'University of Potsdam', 'https://ror.org/03bnmw459', 'ROR');

        $this->createLandingPage($resource, 'contact-persons');

        $this->logCreation($resource, 'Contact persons with email/website');
    }

    /**
     * Scenario: Sizes and formats.
     */
    private function createSizesAndFormats(): void
    {
        $resource = $this->createBaseResource('TEST: Multiple Sizes and Formats');

        // Note: Default contact person is already at position 1
        $this->addCreator($resource, 'File', 'Manager', null, 2);

        Size::create(['resource_id' => $resource->id, 'value' => '2.5 GB']);
        Size::create(['resource_id' => $resource->id, 'value' => '150,000 records']);
        Size::create(['resource_id' => $resource->id, 'value' => '500 files']);

        Format::create(['resource_id' => $resource->id, 'value' => 'application/zip']);
        Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
        Format::create(['resource_id' => $resource->id, 'value' => 'application/x-netcdf']);
        Format::create(['resource_id' => $resource->id, 'value' => 'application/json']);

        $this->createLandingPage($resource, 'sizes-and-formats');

        $this->logCreation($resource, '3 sizes, 4 formats');
    }

    /**
     * Create resource with download URL (FTP) configured.
     *
     * Tests FilesSection showing download button (Issue #373).
     */
    private function createFilesWithDownloadUrl(): void
    {
        $resource = $this->createBaseResource('TEST: Files With Download URL');

        $this->addCreator($resource, 'Data', 'Distributor', null, 2);

        $this->createLandingPage($resource, 'files-with-download-url', [
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test-data.zip',
        ]);

        $this->logCreation($resource, 'Landing page with download URL configured');
    }

    /**
     * Create resource with contact person but no download URL.
     *
     * Tests FilesSection showing "Request data via contact form" button (Issue #373).
     * The fallback to contact form is based on having a contact person with email,
     * not a separate contact_url field.
     */
    private function createFilesWithContactPersonOnly(): void
    {
        $resource = $this->createBaseResource('TEST: Files With Contact Person Only');

        // Add contact person WITH email (is_contact=true, email set)
        // This triggers the contact form fallback in FilesSection
        $this->addContactPersonWithEmail($resource, 'Contact', 'Person', 'contact@example.org');

        $this->createLandingPage($resource, 'files-with-contact-person-only', [
            'ftp_url' => null,
        ]);

        $this->logCreation($resource, 'Landing page with contact person email (no FTP)');
    }

    /**
     * Create resource with neither download URL nor contact person with email.
     *
     * Tests FilesSection showing fallback message (Issue #373).
     */
    private function createFilesWithNoContactOptions(): void
    {
        $resource = $this->createBaseResource('TEST: Files With No Contact Options');

        // Regular creator without contact info (is_contact=false)
        $this->addCreator($resource, 'No', 'Download', null, 2);

        $this->createLandingPage($resource, 'files-with-no-contact-options', [
            'ftp_url' => null,
        ]);

        $this->logCreation($resource, 'Landing page with no download or contact person email');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create base resource with mandatory fields.
     *
     * Creates a resource with all mandatory fields including:
     * - DOI, publication year, resource type, language, publisher, version
     * - Main title
     * - Abstract (min 50 characters)
     * - Default contact person with email (mandatory in ERNIE)
     * - CC-BY-4.0 license
     *
     * @param  string  $title  The main title of the resource
     * @param  string|null  $version  The version number (default: 1.0)
     * @param  string|null  $abstract  The abstract text (if null, a default abstract is created based on the title)
     * @param  bool  $addDefaultContact  Whether to add a default contact person (default: true)
     */
    private function createBaseResource(
        string $title,
        ?string $version = null,
        ?string $abstract = null,
        bool $addDefaultContact = true
    ): Resource {
        $uniqueId = uniqid();

        $resource = Resource::create([
            'doi' => "10.5880/testdata.{$uniqueId}",
            'publication_year' => now()->year,
            'resource_type_id' => $this->resourceType->id,
            'language_id' => $this->language->id,
            'publisher_id' => $this->publisher->id,
            'version' => $version ?? '1.0',
        ]);

        // Add main title (using MainTitle type)
        Title::create([
            'resource_id' => $resource->id,
            'value' => $title,
            'title_type_id' => $this->mainTitleType->id,
        ]);

        // Add abstract (mandatory field in ERNIE - min 50 characters)
        // Generate a default abstract based on the title if none provided
        $abstractText = $abstract ?? "This is a test resource for the scenario: {$title}. This dataset was created by the ResourceTestDataSeeder to validate the metadata editor functionality and landing page display. It contains sample data for development and testing purposes only.";

        Description::create([
            'resource_id' => $resource->id,
            'value' => $abstractText,
            'description_type_id' => $this->abstractType->id,
        ]);

        // Add CC-BY-4.0 license (mandatory field in ERNIE)
        $resource->rights()->attach($this->ccByLicense->id);

        // Add default contact person with email (mandatory in ERNIE)
        if ($addDefaultContact) {
            $this->addCreator(
                $resource,
                'Contact',
                'Person',
                null, // No ORCID for default contact
                1,
                true, // is_contact
                'contact@gfz-potsdam.de'
            );
        }

        $this->createdResourceIds[] = $resource->id;

        return $resource;
    }

    /**
     * Add a person creator to a resource.
     */
    private function addCreator(
        Resource $resource,
        string $givenName,
        string $familyName,
        ?string $orcid = null,
        int $position = 1,
        bool $isContact = false,
        ?string $email = null,
        ?string $website = null
    ): ResourceCreator {
        // If ORCID is provided, search by ORCID first (unique constraint)
        if ($orcid) {
            $person = Person::where('name_identifier', $orcid)->first();
            if (! $person) {
                $person = Person::create([
                    'given_name' => $givenName,
                    'family_name' => $familyName,
                    'name_identifier' => $orcid,
                    'name_identifier_scheme' => 'ORCID',
                    'scheme_uri' => 'https://orcid.org/',
                ]);
            }
        } else {
            // No ORCID - search by name
            $person = Person::firstOrCreate(
                [
                    'given_name' => $givenName,
                    'family_name' => $familyName,
                    'name_identifier' => null,
                ],
                []
            );
        }

        return ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => $position,
            'is_contact' => $isContact,
            'email' => $email,
            'website' => $website,
        ]);
    }

    /**
     * Add a person contributor to a resource.
     */
    private function addContributor(
        Resource $resource,
        string $givenName,
        string $familyName,
        ContributorType $type,
        ?string $orcid = null,
        int $position = 1
    ): ResourceContributor {
        // If ORCID is provided, search by ORCID first (unique constraint)
        if ($orcid) {
            $person = Person::where('name_identifier', $orcid)->first();
            if (! $person) {
                $person = Person::create([
                    'given_name' => $givenName,
                    'family_name' => $familyName,
                    'name_identifier' => $orcid,
                    'name_identifier_scheme' => 'ORCID',
                    'scheme_uri' => 'https://orcid.org/',
                ]);
            }
        } else {
            // No ORCID - search by name
            $person = Person::firstOrCreate(
                [
                    'given_name' => $givenName,
                    'family_name' => $familyName,
                    'name_identifier' => null,
                ],
                []
            );
        }

        return ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'contributor_type_id' => $type->id,
            'position' => $position,
        ]);
    }

    /**
     * Add an affiliation to a creator or contributor.
     */
    private function addAffiliation(
        ResourceCreator|ResourceContributor $entity,
        string $name,
        ?string $identifier = null,
        ?string $scheme = null
    ): Affiliation {
        return Affiliation::create([
            'affiliatable_type' => $entity::class,
            'affiliatable_id' => $entity->id,
            'name' => $name,
            'identifier' => $identifier,
            'identifier_scheme' => $scheme,
        ]);
    }

    /**
     * Add a contact person with email to a resource.
     *
     * Convenience method for creating a creator who is marked as a contact person
     * with an email address. Used for testing FilesSection contact form fallback.
     */
    private function addContactPersonWithEmail(
        Resource $resource,
        string $givenName,
        string $familyName,
        string $email,
        int $position = 1
    ): ResourceCreator {
        return $this->addCreator(
            $resource,
            $givenName,
            $familyName,
            null, // no ORCID
            $position,
            true, // isContact
            $email,
            null // no website
        );
    }

    /**
     * Create a published landing page for a resource.
     *
     * Note: Slugs are deterministic (no unique ID) to allow Playwright tests to navigate to them.
     *
     * @param  array<string, string|null>  $options  Optional landing page configuration (ftp_url)
     */
    private function createLandingPage(Resource $resource, string $slug, array $options = []): LandingPage
    {
        return LandingPage::create([
            'resource_id' => $resource->id,
            'slug' => $slug,
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now(),
            'preview_token' => bin2hex(random_bytes(32)),
            'ftp_url' => $options['ftp_url'] ?? null,
        ]);
    }

    /**
     * Log resource creation.
     */
    private function logCreation(Resource $resource, string $description): void
    {
        $this->command->info("  ✓ Created: {$resource->main_title}");
        $this->command->line("    → {$description}");
    }

    /**
     * Output summary table of created resources.
     */
    private function outputSummary(): void
    {
        $this->command->info('Created Test Resources Summary:');
        $this->command->newLine();

        $resources = Resource::whereIn('id', $this->createdResourceIds)
            ->with(['titles', 'creators', 'contributors', 'geoLocations', 'rights'])
            ->get();

        $tableData = [];
        foreach ($resources as $resource) {
            // Get first title (main title is typically the first one created)
            $firstTitle = $resource->titles->first();
            $title = $firstTitle ? $firstTitle->value : 'N/A';
            $tableData[] = [
                $resource->id,
                \Illuminate\Support\Str::limit($title, 50),
                $resource->creators->count(),
                $resource->contributors->count(),
                $resource->geoLocations->count(),
                $resource->rights->count(),
            ];
        }

        $this->command->table(
            ['ID', 'Title', 'Creators', 'Contributors', 'GeoLocs', 'Rights'],
            $tableData
        );
    }
}
