<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\PortalScope;
use App\Models\Datacenter;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\IgsnPortalFacetService;
use App\Services\PortalFilterService;
use App\Services\PortalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE,
    ]);
    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    $this->searchService = app(PortalSearchService::class);
    $this->facetService = app(IgsnPortalFacetService::class);
});

/**
 * @param  list<array{value: string, type?: IgsnClassificationType|null}>  $classifications
 * @param  list<string>  $ages
 * @param  list<string>  $units
 */
function createPublishedIgsnForPortalFilters(
    ResourceType $type,
    string $sampleType,
    string $material,
    array $classifications = [],
    array $ages = [],
    array $units = [],
    ?Datacenter $datacenter = null,
    bool $published = true,
): Resource {
    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'datacenter_id' => $datacenter?->id,
    ]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => $published,
        'published_at' => $published ? now() : null,
    ]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'sample_type' => $sampleType,
        'material' => $material,
    ]);

    foreach ($classifications as $position => $classification) {
        IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => $classification['value'],
            'classification_type' => $classification['type'] ?? null,
            'position' => $position,
        ]);
    }
    foreach ($ages as $position => $value) {
        IgsnGeologicalAge::create(['resource_id' => $resource->id, 'value' => $value, 'position' => $position]);
    }
    foreach ($units as $position => $value) {
        IgsnGeologicalUnit::create(['resource_id' => $resource->id, 'value' => $value, 'position' => $position]);
    }

    return $resource;
}

function igsnPortalFilters(array $overrides = []): array
{
    return [
        'portal_scope' => PortalScope::IGSN->value,
        'sample_types' => [],
        'materials' => [],
        'classifications' => [],
        'geological_ages' => [],
        'geological_units' => [],
        ...$overrides,
    ];
}

it('normalizes the five IGSN filters only for the IGSN scope', function (): void {
    $request = Request::create('/igsn-search', 'GET', [
        'sample_types' => [' Core ', 'Core'],
        'materials' => ['Rock'],
        'classifications' => ['Igneous', 'Metamorphic'],
        'geological_ages' => ['Jurassic'],
        'geological_units' => ['Upper Rhine Graben'],
        'thesaurus_keywords' => ['hidden-legacy-filter'],
    ]);
    $service = app(PortalFilterService::class);

    $igsn = $service->fromRequest($request, [], PortalScope::IGSN);
    $doi = $service->fromRequest($request, [], PortalScope::DOI);

    expect($igsn)->toMatchArray([
        'sample_types' => ['Core'],
        'materials' => ['Rock'],
        'classifications' => ['Igneous', 'Metamorphic'],
        'geological_ages' => ['Jurassic'],
        'geological_units' => ['Upper Rhine Graben'],
        'thesaurus_keywords' => [],
    ])->and($service->forFrontend($igsn))->toMatchArray([
        'sampleTypes' => ['Core'],
        'materials' => ['Rock'],
        'classifications' => ['Igneous', 'Metamorphic'],
        'geologicalAges' => ['Jurassic'],
        'geologicalUnits' => ['Upper Rhine Graben'],
    ])->and($doi)->toMatchArray([
        'sample_types' => [],
        'materials' => [],
        'classifications' => [],
        'geological_ages' => [],
        'geological_units' => [],
        'thesaurus_keywords' => ['hidden-legacy-filter'],
    ]);
});

it('trims, deduplicates, preserves case, and limits direct URL values', function (): void {
    $rawSampleTypes = [' Core ', 'Core', '', 'core', 42];
    foreach (range(1, 25) as $number) {
        $rawSampleTypes[] = "Value {$number}";
    }

    $filters = app(PortalFilterService::class)->fromRequest(
        Request::create('/igsn-search', 'GET', ['sample_types' => $rawSampleTypes]),
        [],
        PortalScope::IGSN,
    );

    expect($filters['sample_types'])->toHaveCount(20)
        ->and($filters['sample_types'])->toBe([
            'Core',
            'core',
            ...array_map(static fn (int $number): string => "Value {$number}", range(1, 18)),
        ]);
});

it('rejects overlong IGSN filter values consistently on page and count endpoints', function (string $routeName): void {
    $overlongValue = str_repeat('x', 256);
    $invalidFilters = [
        'sample_types' => [$overlongValue],
        'materials' => [$overlongValue],
        'classifications' => [$overlongValue],
        'geological_ages' => [$overlongValue],
        'geological_units' => [$overlongValue],
    ];

    $this->getJson(route($routeName, $invalidFilters))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'sample_types.0',
            'materials.0',
            'classifications.0',
            'geological_ages.0',
            'geological_units.0',
        ]);
})->with([
    'page' => 'portal.igsn',
    'count' => 'portal.igsn.count',
]);

it('ignores invalid IGSN-only filter values on the DOI page', function (): void {
    $ignoredFilters = [
        'sample_types' => 'not-an-array',
        'materials' => array_fill(0, 21, 'Rock'),
        'classifications' => [str_repeat('x', 256)],
        'geological_ages' => [42],
        'geological_units' => 'not-an-array',
    ];

    $this->get(route('portal.doi', $ignoredFilters))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.sampleTypes', [])
            ->where('filters.materials', [])
            ->where('filters.classifications', [])
            ->where('filters.geologicalAges', [])
            ->where('filters.geologicalUnits', []));
});

it('ignores invalid IGSN-only filter values on the DOI count endpoint', function (): void {
    $ignoredFilters = [
        'sample_types' => 'not-an-array',
        'materials' => array_fill(0, 21, 'Rock'),
        'classifications' => [str_repeat('x', 256)],
        'geological_ages' => [42],
        'geological_units' => 'not-an-array',
    ];

    $baseline = $this->getJson(route('portal.doi.count'))->assertOk()->json();
    $withIgnoredFilters = $this->getJson(route('portal.doi.count', $ignoredFilters))->assertOk()->json();

    expect($withIgnoredFilters['filter_fingerprint'])->toBe($baseline['filter_fingerprint'])
        ->and($withIgnoredFilters['total'])->toBe($baseline['total']);
});

it('uses OR for sample types and materials and AND across those facets', function (): void {
    $coreRock = createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Core', 'Rock');
    $sampleSediment = createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Core Sample', 'Sediment');
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Specimen', 'Biology');

    expect($this->searchService->count(igsnPortalFilters(['sample_types' => ['Core', 'Core Sample']])))->toBe(2)
        ->and($this->searchService->count(igsnPortalFilters(['materials' => ['Rock', 'Sediment']])))->toBe(2)
        ->and($this->searchService->count(igsnPortalFilters([
            'sample_types' => ['Core', 'Core Sample'],
            'materials' => ['Rock'],
        ])))->toBe(1)
        ->and($this->searchService->search(igsnPortalFilters([
            'sample_types' => ['Core'],
            'materials' => ['Rock'],
        ]))->pluck('id')->all())->toBe([$coreRock->id])
        ->and($sampleSediment->id)->not->toBe($coreRock->id);
});

it('uses AND within every multi-valued IGSN relation', function (): void {
    $complete = createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Rock',
        [
            ['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK],
            ['value' => 'Metamorphic', 'type' => IgsnClassificationType::ROCK],
        ],
        ['Jurassic', 'Cretaceous'],
        ['Unit A', 'Unit B'],
    );
    createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Rock',
        [['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK]],
        ['Jurassic'],
        ['Unit A'],
    );

    expect($this->searchService->search(igsnPortalFilters([
        'classifications' => ['Igneous', 'Metamorphic'],
    ]))->pluck('id')->all())->toBe([$complete->id])
        ->and($this->searchService->search(igsnPortalFilters([
            'geological_ages' => ['Jurassic', 'Cretaceous'],
        ]))->pluck('id')->all())->toBe([$complete->id])
        ->and($this->searchService->search(igsnPortalFilters([
            'geological_units' => ['Unit A', 'Unit B'],
        ]))->pluck('id')->all())->toBe([$complete->id]);
});

it('resolves material parents and rejects unknown material nodes', function (): void {
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Water Sample', 'Liquid>aqueous');
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Porewater Sample', 'Liquid>aqueous>porewater');
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Rock Sample', 'Rock');

    expect($this->searchService->count(igsnPortalFilters(['materials' => ['Liquid']])))->toBe(2)
        ->and($this->searchService->count(igsnPortalFilters(['materials' => ['Unknown material']])))->toBe(0);
});

it('does not apply IGSN metadata filters outside the IGSN scope', function (): void {
    $dataset = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    LandingPage::factory()->create(['resource_id' => $dataset->id, 'is_published' => true, 'published_at' => now()]);

    expect($this->searchService->count([
        'portal_scope' => PortalScope::DOI->value,
        'sample_types' => ['Never matches'],
        'materials' => ['Unknown material'],
    ]))->toBe(1);
});

it('excludes unpublished samples from filter results', function (): void {
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Core', 'Rock', published: true);
    createPublishedIgsnForPortalFilters($this->physicalObjectType, 'Core', 'Rock', published: false);

    expect($this->searchService->count(igsnPortalFilters([
        'sample_types' => ['Core'],
        'materials' => ['Rock'],
    ])))->toBe(1);
});

it('returns contextual distinct counts and retains selected zero-count values', function (): void {
    $icdp = Datacenter::factory()->create(['name' => 'ICDP']);
    $gfz = Datacenter::factory()->create(['name' => 'GFZ']);
    createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Rock',
        [
            ['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK],
            ['value' => 'Metamorphic', 'type' => IgsnClassificationType::ROCK],
        ],
        ['Jurassic', 'Cretaceous'],
        ['Unit A', 'Unit B'],
        $icdp,
    );
    createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Specimen',
        'Rock',
        [['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK]],
        ['Jurassic'],
        ['Unit A'],
        $gfz,
    );
    createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core Sample',
        'Sediment',
        [['value' => 'Sedimentary', 'type' => IgsnClassificationType::ROCK]],
        ['Holocene'],
        ['Unit C'],
        $gfz,
    );

    $facets = $this->facetService->getFacets(igsnPortalFilters([
        'materials' => ['Rock'],
        'classifications' => ['Igneous'],
        'geological_ages' => ['Jurassic'],
        'geological_units' => ['Unit A'],
    ]));

    expect($facets['sampleTypes'])->toBe([
        ['value' => 'Core', 'label' => 'Core', 'count' => 1],
        ['value' => 'Specimen', 'label' => 'Specimen', 'count' => 1],
    ])->and($facets['materials'])->toHaveCount(1)
        ->and($facets['materials'][0])->toMatchArray(['value' => 'Rock', 'count' => 2])
        ->and($facets['classifications'][0]['options'])->toBe([
            ['value' => 'Igneous', 'label' => 'Igneous', 'count' => 2],
            ['value' => 'Metamorphic', 'label' => 'Metamorphic', 'count' => 1],
        ])->and($facets['geologicalAges'])->toBe([
            ['value' => 'Cretaceous', 'label' => 'Cretaceous', 'count' => 1],
            ['value' => 'Jurassic', 'label' => 'Jurassic', 'count' => 2],
        ])->and($facets['geologicalUnits'])->toBe([
            ['value' => 'Unit A', 'label' => 'Unit A', 'count' => 2],
            ['value' => 'Unit B', 'label' => 'Unit B', 'count' => 1],
        ])->and($facets['datacenters'])->toBe([
            ['name' => 'GFZ', 'count' => 1],
            ['name' => 'ICDP', 'count' => 1],
        ]);

    $zeroFacets = $this->facetService->getFacets(igsnPortalFilters([
        'sample_types' => ['Missing sample type'],
        'classifications' => ['Missing classification'],
        'geological_ages' => ['Missing age'],
        'geological_units' => ['Missing unit'],
        'datacenter' => ['Missing datacenter'],
    ]));

    expect($zeroFacets['sampleTypes'])->toContain([
        'value' => 'Missing sample type',
        'label' => 'Missing sample type',
        'count' => 0,
    ])->and($zeroFacets['classifications'][0])->toMatchArray(['type' => 'unclassified'])
        ->and($zeroFacets['classifications'][0]['options'][0]['count'])->toBe(0)
        ->and($zeroFacets['geologicalAges'][0]['count'])->toBe(0)
        ->and($zeroFacets['geologicalUnits'][0]['count'])->toBe(0)
        ->and($zeroFacets['datacenters'][0])->toBe(['name' => 'Missing datacenter', 'count' => 0]);
});

it('retains a selected classification vocabulary type when other filters produce no rows', function (): void {
    createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Rock',
        [['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK]],
    );

    $facets = $this->facetService->getFacets(igsnPortalFilters([
        'materials' => ['Biology'],
        'classifications' => ['Igneous'],
    ]));

    expect($facets['classifications'])->toBe([[
        'type' => 'rock',
        'label' => 'Rock',
        'options' => [[
            'value' => 'Igneous',
            'label' => 'Igneous',
            'count' => 0,
        ]],
    ]]);
});

it('uses a constant six queries for all IGSN facets regardless of option count', function (): void {
    foreach (range(1, 3) as $number) {
        createPublishedIgsnForPortalFilters(
            $this->physicalObjectType,
            "Sample Type {$number}",
            'Rock',
            [['value' => "Classification {$number}", 'type' => IgsnClassificationType::ROCK]],
            ["Age {$number}"],
            ["Unit {$number}"],
            Datacenter::factory()->create(['name' => "Datacenter {$number}"]),
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $facets = $this->facetService->getFacets(igsnPortalFilters());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(6)
        ->and($facets['sampleTypes'])->toHaveCount(3)
        ->and($facets['classifications'][0]['options'])->toHaveCount(3)
        ->and($facets['geologicalAges'])->toHaveCount(3)
        ->and($facets['geologicalUnits'])->toHaveCount(3)
        ->and($facets['datacenters'])->toHaveCount(3);
});

it('keeps page, count, and map results on one IGSN filter contract', function (): void {
    $matching = createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Rock',
        [['value' => 'Igneous', 'type' => IgsnClassificationType::ROCK]],
        ['Jurassic'],
        ['Unit A'],
    );
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $matching->id]);

    $other = createPublishedIgsnForPortalFilters(
        $this->physicalObjectType,
        'Core',
        'Sediment',
        [['value' => 'Sedimentary', 'type' => IgsnClassificationType::ROCK]],
        ['Holocene'],
        ['Unit B'],
    );
    GeoLocation::factory()->withPoint(13.5, 52.6)->create(['resource_id' => $other->id]);

    $filters = [
        'sample_types' => ['Core'],
        'materials' => ['Rock'],
        'classifications' => ['Igneous'],
        'geological_ages' => ['Jurassic'],
        'geological_units' => ['Unit A'],
    ];

    $pageResponse = $this->get(route('portal.igsn', $filters))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sampleTypes', ['Core'])
            ->where('filters.materials', ['Rock'])
            ->where('filters.classifications', ['Igneous'])
            ->where('filters.geologicalAges', ['Jurassic'])
            ->where('filters.geologicalUnits', ['Unit A'])
            ->where('thesaurusFacets', [])
            ->has('igsnFacets.sampleTypes')
            ->has('igsnFacets.materials')
            ->has('igsnFacets.classifications')
            ->has('igsnFacets.geologicalAges')
            ->has('igsnFacets.geologicalUnits')
            ->has('resources', 1)
        );

    $pageFingerprint = $pageResponse->viewData('page')['props']['pagination']['filter_fingerprint'];
    $countResponse = $this->getJson(route('portal.igsn.count', $filters))
        ->assertOk()
        ->assertJsonPath('total', 1);

    expect($countResponse->json('filter_fingerprint'))->toBe($pageFingerprint);

    $this->getJson(route('portal.igsn.map', [
        ...$filters,
        'viewport' => [
            'north' => 54,
            'south' => 50,
            'east' => 16,
            'west' => 10,
            'width' => 1000,
            'height' => 700,
        ],
        'zoom' => 8,
    ]))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonCount(1, 'features');
});

it('keeps IGSN facets out of the DOI page payload', function (): void {
    $dataset = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    LandingPage::factory()->published()->create(['resource_id' => $dataset->id]);

    $this->get(route('portal.doi', [
        'sample_types' => ['Core'],
        'materials' => ['Rock'],
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sampleTypes', [])
            ->where('filters.materials', [])
            ->where('igsnFacets', null)
            ->has('resources', 1)
        );
});
