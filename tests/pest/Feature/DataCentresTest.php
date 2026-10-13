<?php

declare(strict_types=1);

use App\Enums\PortalScope;
use App\Enums\UserRole;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;
use App\Services\DatacenterNameService;
use App\Services\DataCentreCatalogService;
use App\Services\PortalSearchService;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->dataset = ResourceType::factory()->create(['slug' => 'dataset']);
    $this->sample = ResourceType::factory()->create(['slug' => 'physical-object']);
    $this->publishCentre = function (string $name, bool $published = true, bool $sample = false): Resource {
        $centre = Datacenter::firstOrCreate(['name' => $name]);
        $resource = Resource::factory()->create([
            'datacenter_id' => $centre->id,
            'resource_type_id' => $sample ? $this->sample->id : $this->dataset->id,
        ]);
        Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Publication from '.$name]);
        LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $published]);

        return $resource;
    };
});

it('serves both data centre pages publicly and for every internal role', function (?UserRole $role) {
    if ($role !== null) {
        $this->actingAs(User::factory()->unverified()->create(['role' => $role]));
    }
    ($this->publishCentre)('FID GEO');
    foreach (['data-centres.index' => ['/data-centres', 'data-centres/index'], 'data-centres.description' => ['/data-centres/description', 'data-centres/description']] as $route => [$path, $component]) {
        expect(route($route, absolute: false))->toBe($path);
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('dataCentres', 1)
            ->where('dataCentres.0.slug', 'fid-geo')
            ->where('dataCentres.0.datacenterName', 'FID GEO')
            ->missing('dataCentres.0.sources')
            ->missing('dataCentres.0.reviewedAt')
            ->missing('dataCentres.0.logoSource'));
    }
})->with([null, ...UserRole::cases()]);

it('uses exactly the naturally sorted published DOI facets for both pages', function () {
    ($this->publishCentre)('GEOFON Seismic Networks');
    ($this->publishCentre)('FID GEO');
    ($this->publishCentre)('GEOFON Seismic Events');
    ($this->publishCentre)('FID GEO', sample: true);
    ($this->publishCentre)('IGSN only', sample: true);
    ($this->publishCentre)('Unpublished centre', published: false);
    Datacenter::factory()->create(['name' => 'Empty centre']);

    $expected = ['FID GEO', 'GEOFON Seismic Events', 'GEOFON Seismic Networks'];
    $catalog = app(DataCentreCatalogService::class)->published();
    expect(array_column($catalog, 'datacenterName'))->toBe($expected)
        ->toBe(array_column(app(PortalSearchService::class)->getDatacenterFacets(PortalScope::DOI), 'name'));
    foreach (['/data-centres', '/data-centres/description'] as $path) {
        $this->get($path)->assertInertia(fn (Assert $page) => $page->where('dataCentres', $catalog));
    }
});

it('keeps exact names for search even when editorial names differ', function (string $name, string $slug, string $displayName) {
    $match = ($this->publishCentre)($name);
    ($this->publishCentre)($name, published: false);
    ($this->publishCentre)($name, sample: true);
    ($this->publishCentre)('Another centre');

    $entry = collect(app(DataCentreCatalogService::class)->published())->firstWhere('slug', $slug);
    expect($entry['datacenterName'])->toBe($name)->and($entry['displayName'])->toBe($displayName);
    $this->get('/doi-search?'.http_build_query(['datacenter' => [$entry['datacenterName']]]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal')
        ->where('filters.datacenter', [$name])
        ->has('resources', 1)
        ->where('resources.0.id', $match->id));
})->with([
    ['GFZ German Research Centre for Geosciences', 'gfz', 'GFZ Helmholtz Centre for Geosciences'],
    ['Riesgos', 'riesgos', 'RIESGOS — Multi-risk Analysis in the Andes'],
    ['GEOFON Seismic Events', 'geofon-seismic-events', 'GEOFON Seismic Events'],
    ['GEOFON Seismic Networks', 'geofon-seismic-networks', 'GEOFON Seismic Networks'],
]);

it('keeps the two GEOFON searches separate', function () {
    $events = ($this->publishCentre)('GEOFON Seismic Events');
    $networks = ($this->publishCentre)('GEOFON Seismic Networks');
    foreach (['GEOFON Seismic Events' => $events, 'GEOFON Seismic Networks' => $networks] as $name => $resource) {
        $this->get('/doi-search?'.http_build_query(['datacenter' => [$name]]))
            ->assertInertia(fn (Assert $page) => $page->has('resources', 1)->where('resources.0.id', $resource->id));
    }
});

it('keeps editorial data and existing portal links after a datacenter rename', function (): void {
    $resource = ($this->publishCentre)('FID GEO');
    $datacenter = $resource->datacenter;
    app(DatacenterNameService::class)->rename($datacenter, 'FID GEO Renamed');

    $entry = collect(app(DataCentreCatalogService::class)->published())->firstWhere('slug', 'fid-geo');
    expect($entry)->not->toBeNull()
        ->and($entry['datacenterName'])->toBe('FID GEO Renamed')
        ->and($entry['description'])->not->toBeEmpty();

    foreach (['FID GEO', 'FID GEO Renamed'] as $filterName) {
        $this->get('/doi-search?'.http_build_query(['datacenter' => [$filterName]]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id));
    }
});

it('keeps generated data centre links stable after a datacenter rename', function (): void {
    $resource = ($this->publishCentre)('Unlisted centre');
    $catalog = app(DataCentreCatalogService::class);
    $slug = $catalog->published()[0]['slug'];
    app(DatacenterNameService::class)->rename($resource->datacenter, 'Renamed unlisted centre');

    $entry = $catalog->published()[0];
    expect($entry['slug'])->toBe($slug)
        ->and($entry['datacenterName'])->toBe('Renamed unlisted centre')
        ->and($entry['displayName'])->toBe('Renamed unlisted centre');
});

it('provides stable distinct text fallbacks for new names including punctuation and non-Latin scripts', function () {
    foreach (['A+B', 'A B', '地球科学'] as $name) {
        ($this->publishCentre)($name);
    }
    $service = app(DataCentreCatalogService::class);
    $entries = $service->published();
    expect($entries)->toHaveCount(3)->toBe($service->published())
        ->and(array_unique(array_column($entries, 'slug')))->toHaveCount(3);
    foreach ($entries as $entry) {
        expect($entry['slug'])->toMatch('/^datacenter-[a-f0-9]{64}$/')
            ->and($entry['logo'])->toBeNull()
            ->and($entry['displayName'])->toBe($entry['datacenterName'])
            ->and($entry['description'])->not->toBeEmpty()
            ->and($entry['links'])->toBe([]);
    }
});

it('returns an empty catalogue when only unpublished or IGSN data exist', function () {
    ($this->publishCentre)('FID GEO', published: false);
    ($this->publishCentre)('IGSN only', sample: true);
    foreach (['/data-centres', '/data-centres/description'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('dataCentres', []));
    }
});

it('follows portal facet invalidation after publication and withdrawal', function () {
    Cache::flush();
    $resource = ($this->publishCentre)('FID GEO', published: false);
    $catalog = app(DataCentreCatalogService::class);
    expect($catalog->published())->toBe([]);
    $landingPage = $resource->landingPage;
    $landingPage->update(['is_published' => true]);
    expect(array_column($catalog->published(), 'slug'))->toBe(['fid-geo']);
    $landingPage->update(['is_published' => false]);
    expect($catalog->published())->toBe([]);
});

it('has complete reviewed editorial content for the captured production selection', function () {
    $snapshot = json_decode(file_get_contents(base_path('tests/fixtures/data-centres-production.json')), true, flags: JSON_THROW_ON_ERROR);
    $entries = json_decode(file_get_contents(resource_path('data/data-centres.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($entries, 'datacenterName'))->toBe($snapshot['names'])
        ->and(array_unique(array_column($entries, 'slug')))->toHaveCount(count($entries));

    foreach ($entries as $entry) {
        expect($entry['slug'])->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->not->toStartWith('datacenter-')
            ->and($entry['displayName'])->not->toBeEmpty()
            ->and($entry['shortName'])->not->toBeEmpty()
            ->and($entry['description'])->not->toBeEmpty()
            ->and($entry['sources'])->not->toBeEmpty()
            ->and($entry['reviewedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        foreach ($entry['description'] as $paragraph) {
            expect($paragraph)->toBeString()->not->toBeEmpty()->not->toContain('<', '>');
        }
        foreach ($entry['links'] as $link) {
            expect($link['label'])->not->toBeEmpty()
                ->and($link['href'])->toStartWith('https://')->not->toContain('/portal', '/web/find');
        }
        if ($entry['logo'] === null) {
            expect($entry['logoNote'])->not->toBeEmpty();
        } else {
            $path = public_path($entry['logo']['src']);
            expect($entry['logo']['src'])->toStartWith('/images/data-centres/')
                ->and(is_file($path))->toBeTrue()
                ->and($entry['logoSource'])->toStartWith('https://');
            $size = getimagesize($path);
            expect([$entry['logo']['width'], $entry['logo']['height']])->toBe([$size[0], $size[1]]);
        }
    }
});
