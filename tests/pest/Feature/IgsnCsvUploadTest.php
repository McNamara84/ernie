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
    $this->artisan('db:seed', ['--class' => 'FunderIdentifierTypeSeeder']);

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

function getDiveChildrenCsvPath(): string
{
    return base_path('tests/pest/dataset-examples/20260206_TEST_ICDP5071-DIVE-Children-Cores.csv');
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

    it('stores alternate identifiers (name and sample_other_names) from CSV per Issue #465', function () {
        $csvContent = file_get_contents(getDoveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $altIds = $resource->alternateIdentifiers;

        // Should have name (5068_1_A) as alternateIdentifier with type "Local accession number"
        expect($altIds->count())->toBeGreaterThanOrEqual(1);

        $nameAltId = $altIds->firstWhere('value', '5068_1_A');
        expect($nameAltId)->not->toBeNull();
        expect($nameAltId->type)->toBe('Local accession number');
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

        // Size entries (stored in sizes table)
        $sizeEntry = $resource->sizes()->where('type', 'core length')->first();
        expect($sizeEntry)->not->toBeNull();
        expect($sizeEntry->numeric_value)->toBe('0.0000')
            ->and($sizeEntry->unit)->toBe('m')
            ->and($sizeEntry->type)->toBe('core length');

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
        expect((float) $metadata->depth_min)->toBe(57.5);
        expect((float) $metadata->depth_max)->toBe(909.5);
        expect($metadata->depth_scale)->toBe('m (depth_drilled)');

        // Size entries (stored in sizes table)
        $sizeEntry = $resource->sizes()->where('type', 'Total Cored Length')->first();
        expect($sizeEntry)->not->toBeNull();
        expect($sizeEntry->numeric_value)->toBe('851.8800')
            ->and($sizeEntry->unit)->toBe('m')
            ->and($sizeEntry->type)->toBe('Total Cored Length');
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

    it('stores funderIdentifierType correctly for Crossref Funder IDs', function () {
        $csvContent = file_get_contents(getDiveCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();

        // Find a funder with a Crossref Funder ID
        $fundersWithCrossrefId = \App\Models\FundingReference::where('resource_id', $resource->id)
            ->whereNotNull('funder_identifier')
            ->where('funder_identifier', 'like', '%doi.org/10.13039%')
            ->get();

        expect($fundersWithCrossrefId->count())->toBeGreaterThan(0);

        // All funders with doi.org/10.13039 should have Crossref Funder ID type
        foreach ($fundersWithCrossrefId as $funder) {
            expect($funder->funderIdentifierType)->not->toBeNull();
            expect($funder->funderIdentifierType->name)->toBe('Crossref Funder ID');
        }
    });

    it('stores null funderIdentifierType when no identifier provided', function () {
        // Create a custom CSV with a funder that has no identifier
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.TEST123|Test Sample|Sample Name|Test Funder Without ID|
CSV;
        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::whereHas('igsnMetadata')->first();
        $funder = \App\Models\FundingReference::where('resource_id', $resource->id)->first();

        expect($funder)->not->toBeNull();
        expect($funder->funder_name)->toBe('Test Funder Without ID');
        expect($funder->funder_identifier)->toBeNull();
        expect($funder->funder_identifier_type_id)->toBeNull();
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

describe('IGSN Contributor Name Verification (Issue #485)', function () {
    it('creates distinct persons for contributors with unique ORCIDs', function () {
        // Verify that two contributors with different ORCIDs are stored as
        // separate Person records, each correctly linked to their own ORCID.

        $csv = <<<'CSV'
igsn|title|name|contributor|contributorType|identifier
10.58052/IGSN.FIRST|Title1|Name1|Zanetti, Alberto; Venier, Marco|Other; Other|https://orcid.org/0000-0001-1111-1111; https://orcid.org/0000-0002-2222-2222
CSV;
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('test.csv', $csv);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = \App\Models\Resource::whereHas('igsnMetadata')->first();
        $contributors = $resource->contributors()->with('contributorable')->orderBy('position')->get();

        expect($contributors)->toHaveCount(2);

        // Each contributor should have a distinct Person
        $person0 = $contributors[0]->contributorable;
        $person1 = $contributors[1]->contributorable;

        expect($person0->family_name)->toBe('Zanetti');
        expect($person0->given_name)->toBe('Alberto');
        expect($person0->name_identifier)->toBe('https://orcid.org/0000-0001-1111-1111');
        expect($person1->family_name)->toBe('Venier');
        expect($person1->given_name)->toBe('Marco');
        expect($person1->name_identifier)->toBe('https://orcid.org/0000-0002-2222-2222');

        // They must be different Person records
        expect($person0->id)->not->toBe($person1->id);
    });

    it('prevents ORCID-based cross-linking with pre-existing persons', function () {
        // Pre-create "Venier, Marco" with a specific ORCID
        \App\Models\Person::create([
            'family_name' => 'Venier',
            'given_name' => 'Marco',
            'name_identifier' => 'https://orcid.org/0000-0002-2222-2222',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // CSV assigns Venier's ORCID to Zanetti (simulating misaligned data)
        $csv = <<<'CSV'
igsn|title|name|contributor|contributorType|identifier
10.58052/IGSN.CROSS|Title|Name|Zanetti, Alberto|Other|https://orcid.org/0000-0002-2222-2222
CSV;
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('test.csv', $csv);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = \App\Models\Resource::whereHas('igsnMetadata')->first();
        $contributors = $resource->contributors()->with('contributorable')->get();

        expect($contributors)->toHaveCount(1);

        // The contributor should be "Zanetti", NOT "Venier"
        $person = $contributors->first()->contributorable;
        expect($person->family_name)->toBe('Zanetti');
        expect($person->given_name)->toBe('Alberto');

        // Venier's person record should remain untouched
        $venier = \App\Models\Person::where('family_name', 'Venier')->first();
        expect($venier)->not->toBeNull();
        expect($venier->name_identifier)->toBe('https://orcid.org/0000-0002-2222-2222');
    });

    it('correctly links contributor when ORCID matches the same person name', function () {
        // Pre-create "Zanetti, Alberto" with ORCID
        $existingPerson = \App\Models\Person::create([
            'family_name' => 'Zanetti',
            'given_name' => 'Alberto',
            'name_identifier' => 'https://orcid.org/0000-0003-3333-3333',
            'name_identifier_scheme' => 'ORCID',
        ]);

        // CSV correctly assigns Zanetti's ORCID to Zanetti
        $csv = <<<'CSV'
igsn|title|name|contributor|contributorType|identifier
10.58052/IGSN.MATCH|Title|Name|Zanetti, Alberto|Other|https://orcid.org/0000-0003-3333-3333
CSV;
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('test.csv', $csv);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = \App\Models\Resource::whereHas('igsnMetadata')->first();
        $contributors = $resource->contributors()->with('contributorable')->get();

        expect($contributors)->toHaveCount(1);

        // Should reuse the existing person record (ORCID + name match)
        $person = $contributors->first()->contributorable;
        expect($person->id)->toBe($existingPerson->id);
        expect($person->family_name)->toBe('Zanetti');
    });
});

describe('IGSN Multi-Value Size Storage', function () {
    it('stores multiple size entries from CSV with semicolon-separated values', function () {
        $csvContent = file_get_contents(getDiveChildrenCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        // Get the first resource (ICDP5071ECX0001 with size "0.9; 146")
        $resource = Resource::where('doi', 'ICDP5071ECX0001')->first();
        expect($resource)->not->toBeNull();

        $sizes = $resource->sizes;
        expect($sizes)->toHaveCount(2);

        // Verify by export_string accessor
        $exportStrings = $sizes->map->export_string->toArray();
        expect($exportStrings)->toContain('0.9 Drilled Length [m]')
            ->and($exportStrings)->toContain('146 Core Diameter [mm]');

        // Verify structured columns for first size
        $drilledLength = $resource->sizes()->where('type', 'Drilled Length')->first();
        expect($drilledLength->numeric_value)->toBe('0.9000')
            ->and($drilledLength->unit)->toBe('m')
            ->and($drilledLength->type)->toBe('Drilled Length');

        // Verify structured columns for second size
        $coreDiameter = $resource->sizes()->where('type', 'Core Diameter')->first();
        expect($coreDiameter->numeric_value)->toBe('146.0000')
            ->and($coreDiameter->unit)->toBe('mm')
            ->and($coreDiameter->type)->toBe('Core Diameter');
    });

    it('stores multiple size entries for each resource in CSV', function () {
        $csvContent = file_get_contents(getDiveChildrenCsvPath());
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        // ICDP5071EC01001 (size "3; 123")
        $resource = Resource::where('doi', 'ICDP5071EC01001')->first();
        expect($resource)->not->toBeNull();

        $sizes = $resource->sizes;
        expect($sizes)->toHaveCount(2);

        $exportStrings = $sizes->map->export_string->toArray();
        expect($exportStrings)->toContain('3 Drilled Length [m]')
            ->and($exportStrings)->toContain('123 Core Diameter [mm]');

        // Verify structured columns
        $drilledLength = $resource->sizes()->where('type', 'Drilled Length')->first();
        expect($drilledLength->numeric_value)->toBe('3.0000')
            ->and($drilledLength->unit)->toBe('m');

        $coreDiameter = $resource->sizes()->where('type', 'Core Diameter')->first();
        expect($coreDiameter->numeric_value)->toBe('123.0000')
            ->and($coreDiameter->unit)->toBe('mm');
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

describe('ISO 8601 Datetime Collection Dates (Issue #508)', function () {
    it('stores full ISO 8601 datetime with timezone from CSV upload', function () {
        $csvContent = file_get_contents(
            base_path('tests/pest/dataset-examples/20260212_TEST_datetime-collection-dates.csv')
        );
        $file = UploadedFile::fake()->createWithContent('datetime-test.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'created' => 2]);

        // Row 1: datetime with timezone already in the date string
        $resource1 = Resource::where('doi', 'TEST-DT-CORE-001')->first();
        expect($resource1)->not->toBeNull();

        $date1 = ResourceDate::where('resource_id', $resource1->id)->first();
        expect($date1->start_date)->toBe('2023-05-15T09:35+02:00')
            ->and($date1->end_date)->toBe('2023-05-15T11:20+02:00');
    });

    it('applies timezone fallback when datetime has no timezone offset', function () {
        $csvContent = file_get_contents(
            base_path('tests/pest/dataset-examples/20260212_TEST_datetime-collection-dates.csv')
        );
        $file = UploadedFile::fake()->createWithContent('datetime-test.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $response->assertStatus(200);

        // Row 2: datetime without timezone — UTC+2 fallback should be applied
        $resource2 = Resource::where('doi', 'TEST-DT-CORE-002')->first();
        expect($resource2)->not->toBeNull();

        $date2 = ResourceDate::where('resource_id', $resource2->id)->first();
        expect($date2->start_date)->toBe('2023-05-15T14:00+02:00')
            ->and($date2->end_date)->toBe('2023-05-15T15:30+02:00');
    });

    it('exports ISO 8601 datetime range in DataCite JSON format', function () {
        // Upload the CSV
        $csvContent = file_get_contents(
            base_path('tests/pest/dataset-examples/20260212_TEST_datetime-collection-dates.csv')
        );
        $file = UploadedFile::fake()->createWithContent('datetime-test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::where('doi', 'TEST-DT-CORE-001')->first();

        $response = $this->actingAs($this->user)
            ->get("/igsns/{$resource->id}/export/json");

        $response->assertOk();
        $json = json_decode($response->streamedContent(), true);
        $attributes = $json['data']['attributes'];

        expect($attributes)->toHaveKey('dates');
        expect($attributes['dates'][0]['dateType'])->toBe('Collected');
        expect($attributes['dates'][0]['date'])
            ->toBe('2023-05-15T09:35+02:00/2023-05-15T11:20+02:00');
    });

    it('exports ISO 8601 datetime range in DataCite XML format', function () {
        // Upload the CSV
        $csvContent = file_get_contents(
            base_path('tests/pest/dataset-examples/20260212_TEST_datetime-collection-dates.csv')
        );
        $file = UploadedFile::fake()->createWithContent('datetime-test.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file]);

        $resource = Resource::where('doi', 'TEST-DT-CORE-001')->first();

        $response = $this->actingAs($this->user)
            ->get(route('resources.export-datacite-xml', $resource));

        $response->assertOk();

        $xml = method_exists($response->baseResponse, 'streamedContent')
            ? $response->streamedContent()
            : $response->getContent();

        expect($xml)->toContain('dateType="Collected"');
        expect($xml)->toContain('2023-05-15T09:35+02:00/2023-05-15T11:20+02:00</date>');
    });
});
