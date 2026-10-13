<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\OaiPmh\EposMslSetService;
use App\Services\OaiPmh\OaiPmhSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('listSets', function () {
    it('advertises the project set when no published resources exist', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->listSets())->toBe([$service->projectSetDefinition()]);
    });

    it('returns resource type sets from published resources', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->listSets();

        $specs = array_column($sets, 'spec');

        expect($specs)->toContain('resourcetype:'.$resource->resourceType->slug);
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

        expect($service->listSets())->toBe([$service->projectSetDefinition()]);
    });
});

it('recognizes only complete free project keywords in both queries and headers', function (string $value, bool $matches) {
    $resource = Resource::factory()->create();
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => $value, 'language' => 'de']);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), EposMslSetService::SPEC)->whereKey($resource->id)->exists())->toBe($matches)
        ->and(in_array(EposMslSetService::SPEC, $service->getSetsForResource($resource), true))->toBe($matches);
})->with([
    'EPOS' => ['EPOS', true],
    'MSL' => ['MSL', true],
    'mixed case' => ['ePoS', true],
    'lowercase' => ['msl', true],
    'spaces' => [' EPOS ', true],
    'all ASCII whitespace' => ["\t\r\n\v\f MSL \t\r\n\v\f", true],
    'empty' => ['', false],
    'whitespace only' => [" \t\n", false],
    'compound' => ['EPOS-MSL', false],
    'prefix' => ['EPOS project', false],
    'suffix' => ['laboratory MSL', false],
    'internal spaces' => ['EP OS', false],
    'internal tab' => ["M\tSL", false],
    'accent' => ['ÉPOS', false],
    'Unicode whitespace is not ASCII whitespace' => ["\u{00a0}EPOS\u{00a0}", false],
    'NUL is not whitespace' => ["\0EPOS\0", false],
]);

it('excludes every controlled subject attribute from project membership', function (string $field) {
    $resource = Resource::factory()->create();
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'EPOS', $field => 'controlled']);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), EposMslSetService::SPEC)->exists())->toBeFalse()
        ->and($service->getSetsForResource($resource))->not->toContain(EposMslSetService::SPEC);
})->with(['subject_scheme', 'scheme_uri', 'value_uri', 'classification_code', 'breadcrumb_path']);

it('treats blank subject attributes as absent', function (?string $blank) {
    $resource = Resource::factory()->create();
    Subject::factory()->create([
        'resource_id' => $resource->id, 'value' => 'MSL', 'subject_scheme' => $blank,
        'scheme_uri' => $blank, 'value_uri' => $blank, 'classification_code' => $blank, 'breadcrumb_path' => $blank,
    ]);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), EposMslSetService::SPEC)->exists())->toBeTrue()
        ->and($service->getSetsForResource($resource))->toContain(EposMslSetService::SPEC);
})->with([null, '', " \t\n\v\f\r"]);

it('does not combine a free unrelated subject with a controlled project keyword', function () {
    $resource = Resource::factory()->create();
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'geology']);
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'EPOS', 'value_uri' => 'https://example.org/epos']);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), EposMslSetService::SPEC)->exists())->toBeFalse();
});

it('counts a resource once when both keywords and duplicates are present', function () {
    $resource = Resource::factory()->create();
    foreach (['EPOS', 'MSL', 'epos'] as $keyword) {
        Subject::factory()->create(['resource_id' => $resource->id, 'value' => $keyword]);
    }
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), EposMslSetService::SPEC)->count())->toBe(1)
        ->and(array_count_values($service->getSetsForResource($resource))[EposMslSetService::SPEC])->toBe(1);
});

it('accepts only the exact project set specification', function () {
    $service = app(OaiPmhSetService::class);
    expect($service->isValidSetSpec('epos-msl'))->toBeTrue();
    foreach (['EPOS-MSL', 'epos', 'msl', 'epos-msl:extra', ' epos-msl', 'epos-msl '] as $spec) {
        expect($service->isValidSetSpec($spec))->toBeFalse();
    }
});

describe('getSetsForResource', function () {
    it('returns type and year sets for a resource', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);

        $service = app(OaiPmhSetService::class);
        $sets = $service->getSetsForResource($resource);

        expect($sets)->toContain('resourcetype:'.$resource->resourceType->slug)
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
    it('accepts resourcetype: prefix with alphanumeric slug', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:dataset'))->toBeTrue();
    });

    it('accepts resourcetype: prefix with hyphens', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:physical-object'))->toBeTrue();
    });

    it('accepts resourcetype: prefix with underscores', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:data_paper'))->toBeTrue();
    });

    it('rejects resourcetype: prefix with spaces', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:Physical Object'))->toBeFalse();
    });

    it('rejects resourcetype: prefix with uppercase letters', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:Dataset'))->toBeFalse()
            ->and($service->isValidSetSpec('resourcetype:PhysicalObject'))->toBeFalse();
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

    it('rejects empty value after year prefix', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('year:'))->toBeFalse();
    });

    it('rejects non-numeric year values', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('year:abcd'))->toBeFalse()
            ->and($service->isValidSetSpec('year:20'))->toBeFalse()
            ->and($service->isValidSetSpec('year:20241'))->toBeFalse();
    });

    it('rejects empty value after resourcetype prefix', function () {
        $service = app(OaiPmhSetService::class);

        expect($service->isValidSetSpec('resourcetype:'))->toBeFalse();
    });
});

describe('applySetFilter', function () {
    it('filters by resource type', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

        $service = app(OaiPmhSetService::class);
        $query = Resource::query();
        $filtered = $service->applySetFilter($query, 'resourcetype:'.$resource->resourceType->slug);

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
