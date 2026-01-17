<?php

use App\Models\DateType;
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

    it('creates additional resources when uploading same CSV twice', function () {
        $csvContent = file_get_contents(getDoveCsvPath());

        // First upload
        $file1 = UploadedFile::fake()->createWithContent('test1.csv', $csvContent);
        $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file1])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'created' => 1]);

        expect(IgsnMetadata::count())->toBe(1);

        // Second upload - currently creates another entry (no unique constraint on doi)
        $file2 = UploadedFile::fake()->createWithContent('test2.csv', $csvContent);
        $response = $this->actingAs($this->user)
            ->post('/dashboard/upload-igsn-csv', ['file' => $file2]);

        $responseData = $response->json();
        // Note: This test documents current behavior - duplicates are allowed
        expect($responseData['success'])->toBe(true);
        expect($responseData['created'])->toBe(1);

        // Two IGSNs exist (no deduplication)
        expect(IgsnMetadata::count())->toBe(2);
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
        expect($creator->creatorable->family_name)->toBe('Gabriel');
        expect($creator->creatorable->given_name)->toBe('Gerald');
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
