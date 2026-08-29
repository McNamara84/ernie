<?php

declare(strict_types=1);

use App\Services\BotProtection\PortalMapCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

covers(PortalMapCacheService::class);

beforeEach(function (): void {
    Cache::flush();
    config([
        'bot_protection.enabled' => true,
        'portal_map.cache_ttl' => 30,
        'portal_map.extent_cache_ttl' => 300,
    ]);
});

it('reuses a short-lived response for the same canonical map request', function (): void {
    $service = new PortalMapCacheService;
    $request = Request::create('/portal/map', 'GET', [
        'type' => ['physical-object', 'dataset'],
        'viewport' => ['west' => 11, 'east' => 15, 'south' => 50, 'north' => 54, 'height' => 700, 'width' => 1000],
        'zoom' => 8,
    ]);
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['schemaVersion' => 1, 'calls' => $calls];
    };

    $first = $service->remember($request, $resolver);
    $second = $service->remember($request, $resolver);

    expect($first)->toBe(['schemaVersion' => 1, 'calls' => 1])
        ->and($second)->toBe($first)
        ->and($calls)->toBe(1);
});

it('generates the same key for semantically identical nested query ordering', function (): void {
    $service = new PortalMapCacheService;
    $first = Request::create('/portal/map', 'GET', [
        'type' => ['dataset', 'physical-object'],
        'viewport' => ['north' => 54, 'south' => 50, 'east' => 15, 'west' => 11, 'width' => 1000, 'height' => 700],
        'zoom' => 8,
    ]);
    $second = Request::create('/portal/map', 'GET', [
        'zoom' => 8,
        'viewport' => ['height' => 700, 'width' => 1000, 'west' => 11, 'east' => 15, 'south' => 50, 'north' => 54],
        'type' => ['physical-object', 'dataset'],
    ]);

    expect($service->keyForRequest($first))->toBe($service->keyForRequest($second));
});

it('shares extent scans across technical viewports with the same semantic filters', function (): void {
    $service = new PortalMapCacheService;
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return [35_638, ['south' => -80.0, 'west' => -170.0, 'north' => 82.0, 'east' => 175.0]];
    };
    $firstFilters = [
        'query' => 'gravity',
        'type' => ['dataset', 'physical-object'],
        'datacenter' => ['GFZ', 'PANGAEA'],
        'bounds' => null,
        'temporal' => null,
        'page' => 1,
        'per_page' => 20,
        'viewport' => ['north' => 54, 'south' => 50, 'east' => 15, 'west' => 11, 'width' => 1000, 'height' => 700],
    ];
    $secondFilters = [
        ...$firstFilters,
        'type' => ['physical-object', 'dataset'],
        'datacenter' => ['PANGAEA', 'GFZ'],
        'page' => 9,
        'per_page' => 100,
        'viewport' => ['north' => 20, 'south' => -20, 'east' => -170, 'west' => 170, 'width' => 360, 'height' => 640],
    ];

    $first = $service->rememberExtent($firstFilters, $resolver);
    $second = $service->rememberExtent($secondFilters, $resolver);

    expect($second)->toBe($first)
        ->and($calls)->toBe(1)
        ->and($service->extentKeyForFilters($firstFilters))->toBe($service->extentKeyForFilters($secondFilters));
});

it('separates extent cache entries when a semantic filter changes', function (): void {
    $service = new PortalMapCacheService;
    $unfiltered = ['query' => null, 'type' => [], 'bounds' => null, 'page' => 1];
    $filtered = [...$unfiltered, 'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0]];

    expect($service->extentKeyForFilters($unfiltered))->not->toBe($service->extentKeyForFilters($filtered));
});

it('bypasses the map cache when bot protection is disabled', function (): void {
    config(['bot_protection.enabled' => false]);
    $calls = 0;
    $resolver = function () use (&$calls): array {
        return ['calls' => ++$calls];
    };
    $service = new PortalMapCacheService;
    $request = Request::create('/portal/map', 'GET', ['zoom' => 2]);

    expect($service->remember($request, $resolver))->toBe(['calls' => 1])
        ->and($service->remember($request, $resolver))->toBe(['calls' => 2]);
});
