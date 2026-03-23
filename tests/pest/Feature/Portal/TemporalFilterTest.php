<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\PortalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

beforeEach(function () {
    withoutVite();
    Cache::forget(CacheKey::PORTAL_TEMPORAL_RANGE->key());

    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );

    $this->createdType = DateType::firstOrCreate(
        ['slug' => 'Created'],
        ['name' => 'Created', 'is_active' => true],
    );

    $this->collectedType = DateType::firstOrCreate(
        ['slug' => 'Collected'],
        ['name' => 'Collected', 'is_active' => true],
    );

    $this->coverageType = DateType::firstOrCreate(
        ['slug' => 'Coverage'],
        ['name' => 'Coverage', 'is_active' => false],
    );

    $this->searchService = app(PortalSearchService::class);
});

function createPublishedResourceWithDate(
    ResourceType $type,
    string $title,
    DateType $dateType,
    ?string $dateValue = null,
    ?string $startDate = null,
    ?string $endDate = null,
): Resource {
    $mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );

    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'publication_year' => 2024,
    ]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $mainTitleType->id,
        'value' => $title,
    ]);

    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $dateType->id,
        'date_value' => $dateValue,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    return $resource;
}

describe('Temporal Filter - Single Dates', function () {
    it('filters resources by Created date type with single date', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Old Dataset', $this->createdType,
            dateValue: '2010',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'New Dataset', $this->createdType,
            dateValue: '2024',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Created', 'yearFrom' => 2020, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });

    it('filters resources by Collected date type', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Collected Recent', $this->collectedType,
            dateValue: '2023-06-15',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'Collected Old', $this->collectedType,
            dateValue: '1990-01',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Collected', 'yearFrom' => 2020, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });

    it('includes resources where date year exactly matches boundary', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Boundary Dataset', $this->createdType,
            dateValue: '2020',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Created', 'yearFrom' => 2020, 'yearTo' => 2020],
        ]);

        expect($results->total())->toBe(1);
    });
});

describe('Temporal Filter - Date Ranges', function () {
    it('filters resources with closed date range (overlap check)', function () {
        // Range 2015-2020 should match filter 2018-2025
        createPublishedResourceWithDate(
            $this->datasetType, 'Overlapping Range', $this->collectedType,
            startDate: '2015', endDate: '2020',
        );
        // Range 2005-2010 should NOT match filter 2018-2025
        createPublishedResourceWithDate(
            $this->datasetType, 'Non-overlapping Range', $this->collectedType,
            startDate: '2005', endDate: '2010',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Collected', 'yearFrom' => 2018, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });

    it('handles open-ended date ranges (start_date only)', function () {
        // Open-ended from 2020 should match filter 2018-2025
        createPublishedResourceWithDate(
            $this->datasetType, 'Open Ended', $this->collectedType,
            startDate: '2020',
        );
        // Open-ended from 2030 should NOT match filter 2018-2025
        createPublishedResourceWithDate(
            $this->datasetType, 'Future Open Ended', $this->collectedType,
            startDate: '2030',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Collected', 'yearFrom' => 2018, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });

    it('matches range that fully encloses the filter range', function () {
        // Range 2000-2030 fully encloses filter 2010-2020
        createPublishedResourceWithDate(
            $this->datasetType, 'Enclosing Range', $this->collectedType,
            startDate: '2000', endDate: '2030',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Collected', 'yearFrom' => 2010, 'yearTo' => 2020],
        ]);

        expect($results->total())->toBe(1);
    });
});

describe('Temporal Filter - Coverage Date Type', function () {
    it('filters by Coverage when date type is active', function () {
        $this->coverageType->update(['is_active' => true]);

        createPublishedResourceWithDate(
            $this->datasetType, 'Coverage Dataset', $this->coverageType,
            dateValue: '2022',
        );

        $results = $this->searchService->search([
            'temporal' => ['dateType' => 'Coverage', 'yearFrom' => 2020, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });
});

describe('Temporal Filter - No Temporal Filter', function () {
    it('returns all resources when temporal filter is null', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Dataset A', $this->createdType,
            dateValue: '2010',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'Dataset B', $this->createdType,
            dateValue: '2024',
        );

        $results = $this->searchService->search([]);

        expect($results->total())->toBe(2);
    });
});

describe('Temporal Filter - Combined With Other Filters', function () {
    it('works in combination with text search', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Earthquake Data', $this->createdType,
            dateValue: '2023',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'Climate Data', $this->createdType,
            dateValue: '2023',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'Earthquake Old', $this->createdType,
            dateValue: '2005',
        );

        $results = $this->searchService->search([
            'query' => 'Earthquake',
            'temporal' => ['dateType' => 'Created', 'yearFrom' => 2020, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });

    it('works in combination with type filter', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject',
            'slug' => 'physical-object',
        ]);

        createPublishedResourceWithDate(
            $this->datasetType, 'DOI Resource', $this->createdType,
            dateValue: '2023',
        );
        createPublishedResourceWithDate(
            $physicalObjectType, 'IGSN Sample', $this->createdType,
            dateValue: '2023',
        );

        $results = $this->searchService->search([
            'type' => 'doi',
            'temporal' => ['dateType' => 'Created', 'yearFrom' => 2020, 'yearTo' => 2025],
        ]);

        expect($results->total())->toBe(1);
    });
});

describe('Temporal Range Data', function () {
    it('returns correct temporal range for active date types', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Old', $this->createdType,
            dateValue: '1995',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'New', $this->createdType,
            dateValue: '2024',
        );

        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->toHaveKey('Created');
        expect($ranges['Created']['min'])->toBe(1995);
        expect($ranges['Created']['max'])->toBe(2024);
    });

    it('excludes inactive date types from temporal range', function () {
        $this->coverageType->update(['is_active' => false]);

        createPublishedResourceWithDate(
            $this->datasetType, 'Coverage Data', $this->coverageType,
            dateValue: '2020',
        );

        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->not->toHaveKey('Coverage');
    });

    it('excludes date types with no published resources', function () {
        // No published resources at all
        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->toBeEmpty();
    });

    it('returns empty range when all date types are inactive', function () {
        $this->createdType->update(['is_active' => false]);
        $this->collectedType->update(['is_active' => false]);
        $this->coverageType->update(['is_active' => false]);

        createPublishedResourceWithDate(
            $this->datasetType, 'Some Dataset', $this->createdType,
            dateValue: '2020',
        );

        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->toBeEmpty();
    });

    it('considers date ranges for min/max calculation', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Range Resource', $this->collectedType,
            startDate: '1985', endDate: '2020',
        );

        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->toHaveKey('Collected');
        expect($ranges['Collected']['min'])->toBe(1985);
        expect($ranges['Collected']['max'])->toBe(2020);
    });

    it('treats open-ended ranges as extending to current year for max calculation', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Open Ended Range', $this->collectedType,
            startDate: '2010',
        );

        $ranges = $this->searchService->getTemporalRange();

        expect($ranges)->toHaveKey('Collected');
        expect($ranges['Collected']['min'])->toBe(2010);
        expect($ranges['Collected']['max'])->toBe((int) date('Y'));
    });
});

describe('Temporal Filter - Controller URL Parsing', function () {
    it('parses valid temporal filter parameters from URL', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Test Dataset', $this->createdType,
            dateValue: '2023',
        );

        // year_from/year_to are clamped to the computed range (2023–2023)
        $this->get('/portal?date_type=Created&year_from=2020&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal.dateType', 'Created')
                ->where('filters.temporal.yearFrom', 2023)
                ->where('filters.temporal.yearTo', 2023)
                ->where('pagination.total', 1)
            );
    });

    it('ignores temporal filter when date_type is invalid', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Test Dataset', $this->createdType,
            dateValue: '2023',
        );

        $this->get('/portal?date_type=InvalidType&year_from=2020&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
                ->where('pagination.total', 1)
            );
    });

    it('ignores temporal filter when year_from > year_to', function () {
        $this->get('/portal?date_type=Created&year_from=2025&year_to=2020')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('ignores temporal filter when parameters are missing', function () {
        $this->get('/portal?date_type=Created')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('passes temporalRange prop to frontend', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Test Dataset', $this->createdType,
            dateValue: '2023',
        );

        $this->get('/portal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('temporalRange')
            );
    });

    it('ignores temporal filter when year_from is not numeric', function () {
        $this->get('/portal?date_type=Created&year_from=abc&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('ignores temporal filter when year_from is out of range (too low)', function () {
        $this->get('/portal?date_type=Created&year_from=1800&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('ignores temporal filter when year_to is out of range (too high)', function () {
        $futureYear = (int) date('Y') + 5;
        $this->get("/portal?date_type=Created&year_from=2000&year_to={$futureYear}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('ignores temporal filter when date_type exists but is inactive', function () {
        $this->coverageType->update(['is_active' => false]);

        createPublishedResourceWithDate(
            $this->datasetType, 'Coverage Dataset', $this->coverageType,
            dateValue: '2022',
        );

        $this->get('/portal?date_type=Coverage&year_from=2020&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });

    it('clamps year_from and year_to to the computed temporal range', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Dataset A', $this->createdType,
            dateValue: '2015',
        );
        createPublishedResourceWithDate(
            $this->datasetType, 'Dataset B', $this->createdType,
            dateValue: '2020',
        );

        // Range is 2015–2020. year_from=2010 should clamp to 2015, year_to=2025 should clamp to 2020.
        $this->get('/portal?date_type=Created&year_from=2010&year_to=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal.dateType', 'Created')
                ->where('filters.temporal.yearFrom', 2015)
                ->where('filters.temporal.yearTo', 2020)
            );
    });

    it('discards temporal filter when clamping inverts the range', function () {
        createPublishedResourceWithDate(
            $this->datasetType, 'Dataset', $this->createdType,
            dateValue: '2020',
        );

        // Range is 2020–2020. year_from=2021 clamps to max(2021,2020)=2021 > year_to=2020 → null
        // Actually year_from=2021 > year_to, so basic validation already rejects.
        // Use a case where both are valid individually but clamping inverts:
        // Range 2020–2020, year_from=1900 year_to=2019 → clamped to 2020..2019 → inverted → null
        $this->get('/portal?date_type=Created&year_from=1900&year_to=2019')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.temporal', null)
            );
    });
});
