<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\OaiPmh\OaiPmhSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('listSets', function () {
    it('returns empty array when no published resources exist', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->listSets())->toBe([]);
    });

    it('returns resource type sets from published resources', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->listSets();

        $specs = array_column($sets, 'spec');

        expect($specs)->toContain('resourcetype:Dataset');
    });

    it('returns year sets from published resources', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);
        LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->listSets();

        $specs = array_column($sets, 'spec');

        expect($specs)->toContain('year:2024');
    });

    it('does not include sets from unpublished resources', function () {
        $resource = Resource::factory()->create(['publication_year' => 2023]);
        LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);

        expect($service->listSets())->toBe([]);
    });
});

describe('getSetsForResource', function () {
    it('returns type and year sets for a resource', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->getSetsForResource($resource);

        expect($sets)->toContain('resourcetype:Dataset')
            ->and($sets)->toContain('year:2024');
    });

    it('excludes year set when publication_year is null', function () {
        $resource = Resource::factory()->create(['publication_year' => null]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->getSetsForResource($resource);

        $yearSets = array_filter($sets, fn (string $s) => str_starts_with($s, 'year:'));
        expect($yearSets)->toBeEmpty();
    });
});

describe('isValidSetSpec', function () {
    it('accepts resourcetype: prefix', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:Dataset'))->toBeTrue();
    });

    it('accepts year: prefix', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('year:2024'))->toBeTrue();
    });

    it('rejects unknown prefixes', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('unknown:value'))->toBeFalse()
            ->and($service->isValidSetSpec('invalid'))->toBeFalse();
    });
});

describe('applySetFilter', function () {
    it('filters by resource type', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);
        $query = Resource::query();
        $filtered = $service->applySetFilter($query, 'resourcetype:Dataset');

        expect($filtered->count())->toBe(1);
    });

    it('filters by year', function () {
        Resource::factory()->create(['publication_year' => 2024]);
        Resource::factory()->create(['publication_year' => 2023]);

        $service = app(OaiPmhSetService::class);
        $query = Resource::query();
        $filtered = $service->applySetFilter($query, 'year:2024');

        expect($filtered->count())->toBe(1);
    });

    it('returns no results for unknown set specs', function () {
        Resource::factory()->create();

        $service = app(OaiPmhSetService::class);
        $query = Resource::query();
        $filtered = $service->applySetFilter($query, 'unknown:value');

        expect($filtered->count())->toBe(0);
    });
});
