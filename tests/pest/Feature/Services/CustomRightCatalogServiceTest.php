<?php

declare(strict_types=1);

use App\Models\Right;
use App\Services\Rights\CustomRightCatalogService;
use App\Services\Spdx\SpdxLicenseLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

covers(CustomRightCatalogService::class, Right::class);

beforeEach(function (): void {
    $this->service = app(CustomRightCatalogService::class);
});

it('creates deterministic active custom rights with internal identifiers', function (): void {
    $right = $this->service->findOrCreate('  Community Data License  ', ' https://example.test/licenses/community ');

    expect($right->identifier)->toStartWith('CUSTOM-COMMUNITY-DATA-LICENSE-')
        ->and($right->name)->toBe('Community Data License')
        ->and($right->uri)->toBe('https://example.test/licenses/community')
        ->and($right->scheme_uri)->toBeNull()
        ->and($right->is_active)->toBeTrue()
        ->and($right->is_elmo_active)->toBeFalse()
        ->and($right->usage_count)->toBe(0);

    $sameRight = $this->service->findOrCreate('Community Data License', 'https://example.test/licenses/community');

    expect($sameRight->id)->toBe($right->id)
        ->and(Right::query()->count())->toBe(1);
});

it('reuses inactive custom rights with matching normalized name and URI', function (): void {
    $existing = Right::query()->create([
        'identifier' => 'CUSTOM-LEGACY-ID',
        'name' => 'Legacy Custom License',
        'uri' => 'https://example.test/license',
        'scheme_uri' => null,
        'is_active' => false,
        'is_elmo_active' => true,
        'usage_count' => 7,
    ]);

    $right = $this->service->findOrCreate(' legacy custom license ', 'https://example.test/license/');

    expect($right->id)->toBe($existing->id)
        ->and($right->fresh()->is_active)->toBeTrue()
        ->and($right->fresh()->is_elmo_active)->toBeFalse()
        ->and(Right::query()->count())->toBe(1);
});

it('reuses the matching custom right when unrelated reusable rights exist', function (): void {
    foreach (range(1, 20) as $index) {
        Right::query()->create([
            'identifier' => 'CUSTOM-NOISE-'.$index,
            'name' => 'Unrelated Custom License '.$index,
            'uri' => 'https://example.test/unrelated/'.$index,
            'scheme_uri' => null,
            'is_active' => true,
            'is_elmo_active' => false,
        ]);
    }

    $existing = Right::query()->create([
        'identifier' => 'CUSTOM-LEGACY-COMMUNITY',
        'name' => 'Community Data License',
        'uri' => 'https://example.test/licenses/community-data',
        'scheme_uri' => null,
        'is_active' => false,
        'is_elmo_active' => true,
    ]);

    $right = $this->service->findOrCreate(' community data license ', 'https://example.test/licenses/community-data/');

    expect($right->id)->toBe($existing->id)
        ->and($right->fresh()->is_active)->toBeTrue()
        ->and($right->fresh()->is_elmo_active)->toBeFalse()
        ->and(Right::query()->where('name', 'Community Data License')->count())->toBe(1);
});

it('escapes SQL LIKE wildcards when searching reusable custom rights', function (): void {
    Right::query()->create([
        'identifier' => 'CUSTOM-WILDCARD-NOISE',
        'name' => 'Wildcard License',
        'uri' => 'https://example.test/licenses/aX2fbZunderZscore',
        'scheme_uri' => null,
        'is_active' => false,
        'is_elmo_active' => true,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $right = $this->service->findOrCreate('Wildcard License', 'https://example.test/licenses/a%2Fb_under_score');

    DB::disableQueryLog();

    $likeQuery = collect(DB::getQueryLog())->first(
        fn (array $query): bool => str_contains((string) $query['query'], 'LOWER(uri) LIKE')
    );

    expect($likeQuery)->not->toBeNull()
        ->and($likeQuery['query'])->toContain("ESCAPE '!'")
        ->and($likeQuery['bindings'])->toContain('%example.test/licenses/a!%2fb!_under!_score%')
        ->and($right->uri)->toBe('https://example.test/licenses/a%2Fb_under_score')
        ->and(Right::query()->count())->toBe(2);
});

it('distinguishes SPDX catalog rights from custom rights by scheme URI', function (): void {
    $spdx = Right::query()->create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://spdx.org/licenses/MIT.html',
        'scheme_uri' => SpdxLicenseLookup::SCHEME_URI,
    ]);

    $custom = Right::query()->create([
        'identifier' => 'CUSTOM-MIT-LIKE',
        'name' => 'MIT-like internal license',
        'uri' => 'https://example.test/mit-like',
        'scheme_uri' => null,
    ]);

    expect(CustomRightCatalogService::isSpdxRight($spdx))->toBeTrue()
        ->and(CustomRightCatalogService::isCustomRight($spdx))->toBeFalse()
        ->and(CustomRightCatalogService::isSpdxRight($custom))->toBeFalse()
        ->and(CustomRightCatalogService::isCustomRight($custom))->toBeTrue();
});
