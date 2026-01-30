<?php

/**
 * IGSN Sorting Bug Reproduction Test
 *
 * Bug: Sorting by "Collection Date" on /igsns page returns 500 Internal Server Error
 * Reported from: https://ernie.rz-vm182.gfz.de/igsns?sort=collection_date&direction=desc
 *
 * This test verifies that all sort columns work correctly without server errors.
 */

use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed required data for IGSN functionality
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

/**
 * Helper function to create an IGSN resource with optional collection date
 */
function createIgsnResource(string $igsn, ?string $collectionDate = null): Resource
{
    $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::create([
        'doi' => $igsn,
        'publication_year' => '2024',
        'version' => '1.0',
        'resource_type_id' => $physicalObjectType->id,
    ]);

    // Add a title (titles table has no 'position' column)
    $resource->titles()->create([
        'value' => "Sample {$igsn}",
        'title_type_id' => $mainTitleType->id,
    ]);

    // Add IGSN metadata
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'sample_type' => 'rock core',
        'material' => 'granite',
        'upload_status' => 'pending',
    ]);

    // Add collection date if provided
    if ($collectionDate !== null) {
        $collectedDateType = DateType::where('slug', 'Collected')->first();
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'date_value' => $collectionDate,
        ]);
    }

    return $resource;
}

describe('IGSN List Sorting Bug', function () {
    it('can load IGSN list page without any sorting', function () {
        // Create a test IGSN
        createIgsnResource('TEST-IGSN-001');

        $response = $this->actingAs($this->user)->get('/igsns');

        $response->assertStatus(200);
    });

    it('sorts by collection_date descending correctly', function () {
        // Create test IGSNs with different collection dates
        createIgsnResource('TEST-IGSN-EARLY', '2024-01-15');
        createIgsnResource('TEST-IGSN-LATE', '2024-06-20');
        createIgsnResource('TEST-IGSN-MIDDLE', '2024-03-10');
        createIgsnResource('TEST-IGSN-NONE'); // No collection date

        $response = $this->actingAs($this->user)
            ->get('/igsns?sort=collection_date&direction=desc');

        $response->assertStatus(200);

        // Verify sort order: latest date first, null dates last
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $collectionDates = collect($igsns)->pluck('collection_date')->toArray();

        // Filter out nulls for comparison, then verify descending order
        $datesWithValues = array_filter($collectionDates, fn ($d) => $d !== null);
        $sortedDates = $datesWithValues;
        rsort($sortedDates); // descending
        expect(array_values($datesWithValues))->toBe(array_values($sortedDates));
    });

    it('sorts by collection_date ascending correctly', function () {
        createIgsnResource('TEST-IGSN-LATE', '2024-06-20');
        createIgsnResource('TEST-IGSN-EARLY', '2024-01-15');
        createIgsnResource('TEST-IGSN-MIDDLE', '2024-03-10');

        $response = $this->actingAs($this->user)
            ->get('/igsns?sort=collection_date&direction=asc');

        $response->assertStatus(200);

        // Verify sort order: earliest date first
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $collectionDates = collect($igsns)->pluck('collection_date')->toArray();

        $sortedDates = $collectionDates;
        sort($sortedDates); // ascending
        expect($collectionDates)->toBe($sortedDates);
    });

    it('sorts by title alphabetically', function () {
        createIgsnResource('TEST-IGSN-C'); // Title: "Sample TEST-IGSN-C"
        createIgsnResource('TEST-IGSN-A'); // Title: "Sample TEST-IGSN-A"
        createIgsnResource('TEST-IGSN-B'); // Title: "Sample TEST-IGSN-B"

        // Test ascending order
        $response = $this->actingAs($this->user)
            ->get('/igsns?sort=title&direction=asc');

        $response->assertStatus(200);

        $igsns = $response->original->getData()['page']['props']['igsns'];
        $titles = collect($igsns)->pluck('title')->toArray();

        $sortedTitles = $titles;
        sort($sortedTitles); // ascending alphabetical
        expect($titles)->toBe($sortedTitles);

        // Test descending order
        $response = $this->actingAs($this->user)
            ->get('/igsns?sort=title&direction=desc');

        $response->assertStatus(200);

        $igsns = $response->original->getData()['page']['props']['igsns'];
        $titles = collect($igsns)->pluck('title')->toArray();

        $sortedTitles = $titles;
        rsort($sortedTitles); // descending alphabetical
        expect($titles)->toBe($sortedTitles);
    });

    it('can sort by all allowed sort columns without error', function () {
        // Create test data
        createIgsnResource('TEST-IGSN-001', '2024-01-15');
        createIgsnResource('TEST-IGSN-002', '2024-06-20');

        $sortColumns = [
            'id',
            'igsn',
            'title',
            'sample_type',
            'material',
            'collection_date', // This is the failing one
            'upload_status',
            'created_at',
            'updated_at',
        ];

        $errors = [];
        foreach ($sortColumns as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $response = $this->actingAs($this->user)
                    ->get("/igsns?sort={$column}&direction={$direction}");

                if ($response->status() !== 200) {
                    $errors[] = "Sorting by {$column} ({$direction}) returned {$response->status()}";
                }
            }
        }

        expect($errors)->toBeEmpty("Failed columns: " . implode(', ', $errors));
    });

    it('handles resources with date ranges in collection date', function () {
        // Create IGSN with date range (start_date and end_date instead of date_value)
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();
        $collectedDateType = DateType::where('slug', 'Collected')->first();

        $resource = Resource::create([
            'doi' => 'TEST-IGSN-RANGE',
            'publication_year' => '2024',
            'version' => '1.0',
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $resource->titles()->create([
            'value' => 'Sample with date range',
            'title_type_id' => $mainTitleType->id,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'sediment',
            'upload_status' => 'pending',
        ]);

        // Add date range (using start_date and end_date)
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedDateType->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-30',
        ]);

        // This should work with COALESCE(start_date, date_value)
        $response = $this->actingAs($this->user)
            ->get('/igsns?sort=collection_date&direction=desc');

        $response->assertStatus(200);
    });
});
