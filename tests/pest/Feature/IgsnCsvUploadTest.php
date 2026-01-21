<?php

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\FundingReference;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\IgsnCsvParserService;
use App\Services\IgsnStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed required data
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);

    $this->user = User::factory()->admin()->create();
});

function getDoveCsvPath(): string
{
    return base_path('tests/pest/dataset-examples/20260116_TEST_ICDP5068-DOVE-v2-Parent-Boreholes.csv');
}

function getDiveCsvPath(): string
{
    return base_path('tests/pest/dataset-examples/20260116_TEST_ICDP5071-DIVE-Parent-Boreholes.csv');
}

describe('IGSN CSV Upload Controller', function () {
    it('can upload DOVE CSV file via endpoint', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent(
            '20260116_TEST_ICDP5068-DOVE-v2-Parent-Boreholes.csv',
            $csvContent
        );

        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify IGSN was created
        expect(IgsnMetadata::count())->toBe(1);
        expect(Resource::whereHas('igsnMetadata')->count())->toBe(1);

        $resource = Resource::whereHas('igsnMetadata')->first();
        expect($resource->doi)->toBe('ICDP5068EH50001');
    });

    it('can upload DIVE CSV file via endpoint', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent(
            '20260116_TEST_ICDP5071-DIVE-Parent-Boreholes.csv',
            $csvContent
        );

        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        expect($resource->doi)->toBe('ICDP5071EH10001');
    });

    it('rejects duplicate IGSN upload with clear error message', function () {
        $csvContent = file_get_contents(getDoveCsvPath());

        // First upload - should succeed
        $file1 = UploadedFile::fake()->createWithContent('test1.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file1])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'created' => 1]);

        expect(IgsnMetadata::count())->toBe(1);

        // Second upload with same IGSN - should be rejected (IGSN must be globally unique)
        $file2 = UploadedFile::fake()->createWithContent('test2.csv', $csvContent);
        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file2])
            ->assertStatus(422);

        $responseData = $response->json();
        expect($responseData['success'])->toBe(false);
        expect($responseData['message'])->toContain('Duplicate');
        expect($responseData['errors'])->toBeArray();
        expect($responseData['errors'][0]['message'])->toContain('already exists');

        // Only one IGSN should exist (duplicate was rejected)
        expect(IgsnMetadata::count())->toBe(1);
    });
});

describe('IGSN Data Storage', function () {
    it('stores title correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $mainTitleTypeId = TitleType::where('slug', 'MainTitle')->value('id');
        $mainTitle = $resource->titles->firstWhere('title_type_id', $mainTitleTypeId);

        expect($mainTitle)->not->toBeNull();
        expect($mainTitle->value)->toContain('IGSN ICDP5068EH50001');
        expect($mainTitle->value)->toContain('Borehole');
        expect($mainTitle->value)->toContain('Sediment');
    });

    it('stores collection dates correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        expect($collectionDate)->not->toBeNull();
        expect($collectionDate->start_date)->toBe('2021-04-12');
        expect($collectionDate->end_date)->toBe('2021-05-05');
    });

    it('stores collection date with only start date (open-ended range)', function () {
        $csvContent = "igsn|title|name|collection_start_date|collection_end_date\nIGSN123|Test Title|Test Name|2024-06-15|";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        expect($collectionDate)->not->toBeNull();
        expect($collectionDate->start_date)->toBe('2024-06-15');
        expect($collectionDate->end_date)->toBeNull();
    });

    it('stores collection date with year-only format', function () {
        $csvContent = "igsn|title|name|collection_start_date|collection_end_date\nIGSN456|Test Title|Test Name|2020|2024";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        expect($collectionDate)->not->toBeNull();
        expect($collectionDate->start_date)->toBe('2020');
        expect($collectionDate->end_date)->toBe('2024');
    });

    it('stores collection date with year-month format', function () {
        $csvContent = "igsn|title|name|collection_start_date|collection_end_date\nIGSN789|Test Title|Test Name|2024-03|2024-09";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        expect($collectionDate)->not->toBeNull();
        expect($collectionDate->start_date)->toBe('2024-03');
        expect($collectionDate->end_date)->toBe('2024-09');
    });

    it('does not create collection date when both dates are empty', function () {
        $csvContent = "igsn|title|name|collection_start_date|collection_end_date\nIGSN000|Test Title|Test Name||";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        expect($collectionDate)->toBeNull();
    });

    it('stores IGSN metadata correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $metadata = $resource->igsnMetadata;

        expect($metadata)->not->toBeNull();
        expect($metadata->sample_type)->toBe('Borehole');
        expect($metadata->material)->toBe('Sediment');
        expect($metadata->upload_status)->toBe('uploaded');
    });

    it('stores geo location correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $geoLocation = $resource->geoLocations->first();

        expect($geoLocation)->not->toBeNull();
        expect((float) $geoLocation->point_latitude)->toBeBetween(47.999, 48.001);
        expect((float) $geoLocation->point_longitude)->toBeBetween(9.748, 9.750);
    });

    it('stores creator correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator)->not->toBeNull();
        expect($creator->creatorable)->not->toBeNull();
        // Note: CSV has dedicated givenName/familyName columns which are used over collector field
        // givenName=Gabriel, familyName=Gerald (from dedicated columns)
        expect($creator->creatorable->given_name)->toBe('Gabriel');
        expect($creator->creatorable->family_name)->toBe('Gerald');
        expect($creator->creatorable->orcid)->toBe('https://orcid.org/0000-0001-9404-882X');
    });

    it('stores creator with ORCID correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();
        $person = $creator->creatorable;

        // Verify creator is stored with correct name parts from dedicated columns
        // givenName=Gabriel, familyName=Gerald (from dedicated columns, not parsed from collector)
        expect($person->given_name)->toBe('Gabriel');
        expect($person->family_name)->toBe('Gerald');

        // Verify ORCID is stored correctly with scheme
        expect($person->name_identifier)->toBe('https://orcid.org/0000-0001-9404-882X');
        expect($person->name_identifier_scheme)->toBe('ORCID');
    });

    it('stores creator with affiliation from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        // Verify affiliation is linked to creator
        $affiliations = $creator->affiliations;
        expect($affiliations->count())->toBeGreaterThanOrEqual(1);

        $affiliation = $affiliations->first();
        expect($affiliation->name)->toContain('Leibniz');
    });

    it('stores creator from collector name in "FamilyName, GivenName" format', function () {
        $csvContent = "igsn|title|name|collector|orcid\nIGSN-CREATOR-1|Test|Name|Smith, Jane|0000-0002-1234-5678";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator->creatorable->family_name)->toBe('Smith');
        expect($creator->creatorable->given_name)->toBe('Jane');
    });

    it('stores creator from collector name in "GivenName FamilyName" format', function () {
        $csvContent = "igsn|title|name|collector\nIGSN-CREATOR-2|Test|Name|Jane Smith";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator->creatorable->family_name)->toBe('Smith');
        expect($creator->creatorable->given_name)->toBe('Jane');
    });

    it('does not create creator when collector is empty', function () {
        $csvContent = "igsn|title|name|collector\nIGSN-NO-CREATOR|Test|Name|";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        expect($resource->creators->count())->toBe(0);
    });

    it('stores creator from separate givenName and familyName columns', function () {
        $csvContent = "igsn|title|name|givenName|familyName\nIGSN-SEPARATE-1|Test|Name|Max|Mustermann";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator->creatorable->given_name)->toBe('Max');
        expect($creator->creatorable->family_name)->toBe('Mustermann');
    });

    it('prefers givenName/familyName columns over collector field', function () {
        $csvContent = "igsn|title|name|collector|givenName|familyName\nIGSN-PREFER-1|Test|Name|Ignored, Name|Maria|Garcia";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator->creatorable->given_name)->toBe('Maria');
        expect($creator->creatorable->family_name)->toBe('Garcia');
    });

    it('stores creator with only familyName when givenName is empty', function () {
        $csvContent = "igsn|title|name|givenName|familyName\nIGSN-FAMILY-ONLY|Test|Name||Darwin";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $creator = $resource->creators->first();

        expect($creator->creatorable->given_name)->toBeNull();
        expect($creator->creatorable->family_name)->toBe('Darwin');
    });

    it('stores alternative titles (name and sample_other_names) from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $alternativeTitleTypeId = TitleType::where('slug', 'AlternativeTitle')->value('id');
        $altTitles = $resource->titles->where('title_type_id', $alternativeTitleTypeId);

        // Should have name (5068_1_A) as alternative title
        expect($altTitles->count())->toBeGreaterThanOrEqual(1);
        expect($altTitles->pluck('value')->toArray())->toContain('5068_1_A');
    });

    it('stores contributors correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $contributors = $resource->contributors;

        // DOVE has 4 ProjectLeaders
        expect($contributors->count())->toBe(4);

        // Check first contributor has correct data
        $firstContributor = $contributors->first();
        expect($firstContributor->contributorable)->not->toBeNull();
        expect($firstContributor->contributorable->family_name)->toBe('Anselmetti');
        expect($firstContributor->contributorable->given_name)->toBe('Flavio');
    });

    it('stores related identifiers correctly from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $relatedIds = $resource->relatedIdentifiers;

        // DOVE CSV has related identifiers (at least one)
        expect($relatedIds->count())->toBeGreaterThanOrEqual(1);

        // Verify at least one identifier was stored
        $firstId = $relatedIds->first();
        expect($firstId)->not->toBeNull();
        expect($firstId->identifier)->not->toBeEmpty();
    });

    it('stores full IGSN metadata fields from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $metadata = $resource->igsnMetadata;

        // Core fields
        expect($metadata->sample_type)->toBe('Borehole');
        expect($metadata->material)->toBe('Sediment');
        expect($metadata->upload_status)->toBe('uploaded');

        // Size fields (stored as decimal string in DB)
        expect((float) $metadata->size)->toBe(0.0);
        expect($metadata->size_unit)->toBe('core length [m]');

        // Collection method fields
        expect($metadata->collection_method)->toBe('drilling');
        expect($metadata->collection_method_description)->toBe('ROT (rotary drilling)');
        expect($metadata->sample_purpose)->toContain('Flush drilling');

        // Platform fields
        expect($metadata->platform_type)->toBe('drill rig');
        expect($metadata->platform_name)->toBe('UH2');

        // Archive fields
        expect($metadata->current_archive)->toBe('University of Bern, Bern, Switzerland');
        expect($metadata->current_archive_contact)->toBe('Gerald.Gabriel@leibniz-liag.de');

        // Access and program fields
        expect($metadata->sample_access)->toBe('restricted');
        expect($metadata->cruise_field_program)->toBe('ICDP 5068_DOVE');
        expect($metadata->coordinate_system)->toBe('WGS84');
        expect($metadata->operator)->toBe('H. Anger\'s Söhne Bohr- und Brunnenbau GmbH (Hessisch Lichtenau; Germany)');
    });

    it('stores geo location with elevation and place from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $geoLocation = $resource->geoLocations->first();

        expect($geoLocation)->not->toBeNull();
        expect((float) $geoLocation->point_latitude)->toBeBetween(47.999, 48.001);
        expect((float) $geoLocation->point_longitude)->toBeBetween(9.748, 9.750);
        expect((float) $geoLocation->elevation)->toBeBetween(587.8, 588.0);
        expect($geoLocation->elevation_unit)->toBe('meters above sealevel [m asl]');
        expect($geoLocation->place)->toContain('Winterstettenstadt');
        expect($geoLocation->place)->toContain('Germany');
    });

    it('stores geological classifications from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $classifications = \App\Models\IgsnClassification::where('resource_id', $resource->id)->get();

        expect($classifications->count())->toBe(1);
        expect($classifications->first()->value)->toBe('Sedimentary');
    });

    it('stores geological ages from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $ages = \App\Models\IgsnGeologicalAge::where('resource_id', $resource->id)->get();

        expect($ages->count())->toBe(1);
        expect($ages->first()->value)->toBe('Quaternary');
    });

    it('stores description JSON from CSV', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $metadata = $resource->igsnMetadata;

        // Description is a nested JSON with array structure
        expect($metadata->description_json)->not->toBeNull();
        expect($metadata->description_json)->toBeArray();
        // The DOVE description has an outer array containing an object with 'descriptions'
        $firstItem = $metadata->description_json[0] ?? $metadata->description_json;
        expect($firstItem)->toHaveKey('descriptions');
    });
});

describe('IGSN DIVE CSV Data Storage', function () {
    it('stores DIVE CSV with all specific fields correctly', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $metadata = $resource->igsnMetadata;

        // Verify IGSN
        expect($resource->doi)->toBe('ICDP5071EH10001');

        // Verify IGSN metadata specific to DIVE (decimal fields stored as strings)
        expect($metadata->sample_type)->toBe('Borehole');
        expect($metadata->material)->toBe('Rock');
        expect((float) $metadata->size)->toBe(851.88);
        expect($metadata->size_unit)->toBe('Total Cored Length [m]');
        expect((float) $metadata->depth_min)->toBe(57.5);
        expect((float) $metadata->depth_max)->toBe(909.5);
        expect($metadata->depth_scale)->toBe('m (depth_drilled)');
    });

    it('stores DIVE funding references correctly', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $funders = \App\Models\FundingReference::where('resource_id', $resource->id)->get();

        // DIVE has multiple funders
        expect($funders->count())->toBeGreaterThanOrEqual(3);

        // Check specific funders
        $funderNames = $funders->pluck('funder_name')->toArray();
        expect($funderNames)->toContain('Swiss National Science Foundation');
        expect($funderNames)->toContain('DFG German Research Foundation');
    });

    it('stores DIVE geological ages and units correctly', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();

        $ages = \App\Models\IgsnGeologicalAge::where('resource_id', $resource->id)->get();
        $units = \App\Models\IgsnGeologicalUnit::where('resource_id', $resource->id)->get();

        // DIVE has multiple geological ages: "Quaternary, Archean"
        expect($ages->count())->toBe(2);
        $ageValues = $ages->pluck('value')->toArray();
        expect($ageValues)->toContain('Quaternary');
        expect($ageValues)->toContain('Archean');

        // DIVE has geological units: "Permian, Quaternary"
        expect($units->count())->toBe(2);
        $unitValues = $units->pluck('value')->toArray();
        expect($unitValues)->toContain('Permian');
        expect($unitValues)->toContain('Quaternary');
    });

    it('stores DIVE multiple classifications correctly', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $classifications = \App\Models\IgsnClassification::where('resource_id', $resource->id)->get();

        // DIVE has "Igneous; Metamorphic"
        expect($classifications->count())->toBe(2);
        $classValues = $classifications->pluck('value')->toArray();
        expect($classValues)->toContain('Igneous');
        expect($classValues)->toContain('Metamorphic');
    });

    it('stores DIVE contributors with correct types', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $contributors = $resource->contributors;

        // DIVE has 7 contributors (6 ProjectLeaders + 1 SiteManager → falls back to Other)
        expect($contributors->count())->toBe(7);

        // Verify ProjectLeader types are stored
        $projectLeaderType = ContributorType::where('slug', 'ProjectLeader')->first();
        expect($projectLeaderType)->not->toBeNull();

        $projectLeaders = $contributors->where('contributor_type_id', $projectLeaderType->id);
        expect($projectLeaders->count())->toBe(6);
    });
});

describe('IGSN List Page', function () {
    it('displays uploaded IGSNs in table', function () {
        // Upload CSV first
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        // Visit /igsns page
        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/index')
            ->has('igsns', 1)
            ->where('igsns.0.igsn', 'ICDP5068EH50001')
            ->where('igsns.0.sample_type', 'Borehole')
            ->where('igsns.0.material', 'Sediment')
            ->where('igsns.0.upload_status', 'uploaded')
        );
    });

    it('displays collection date correctly', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertInertia(fn ($page) => $page
            ->where('igsns.0.collection_date', '2021-04-12 – 2021-05-05')
        );
    });

    it('displays title correctly', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertInertia(function ($page) {
            $page->has('igsns.0.title');
            $igsns = $page->toArray()['props']['igsns'];
            expect($igsns[0]['title'])->toContain('IGSN ICDP5068EH50001');
        });
    });
});

describe('IGSN Delete', function () {
    it('admin can delete IGSN', function () {
        // Upload CSV first
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();

        // Delete
        $response = $this->actingAs($this->user)
            ->delete("/igsns/{$resource->id}");

        $response->assertRedirect('/igsns');
        expect(Resource::find($resource->id))->toBeNull();
        expect(IgsnMetadata::count())->toBe(0);
    });

    it('non-admin cannot delete IGSN', function () {
        $curator = User::factory()->create(['role' => 'curator']);

        // Upload as admin
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();

        // Try delete as curator
        $response = $this->actingAs($curator)
            ->delete("/igsns/{$resource->id}");

        $response->assertForbidden();
        expect(Resource::find($resource->id))->not->toBeNull();
    });
});

describe('IGSN Exclusion from Resources', function () {
    it('does not show IGSNs on /resources page', function () {
        // Upload IGSN CSV first
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        // Verify IGSN exists
        expect(IgsnMetadata::count())->toBe(1);

        // Visit /resources page - IGSN should NOT appear
        $response = $this->actingAs($this->user)->get('/resources');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('resources')
            ->has('resources', 0) // No resources should be shown (only Physical Objects exist)
        );
    });

    it('does not include Physical Object in filter options', function () {
        // Upload IGSN CSV to create a Physical Object resource
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        // Get filter options
        $response = $this->actingAs($this->user)->get('/resources/filter-options');

        $response->assertStatus(200);
        $responseData = $response->json();

        // Physical Object should NOT be in the resource types
        $resourceTypeSlugs = array_column($responseData['resourceTypes'] ?? [], 'slug');
        expect($resourceTypeSlugs)->not->toContain('physical-object');
    });
});
