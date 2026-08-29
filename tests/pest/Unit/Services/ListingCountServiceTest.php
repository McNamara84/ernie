<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\ListingCountService;
use Illuminate\Support\Facades\Cache;

covers(ListingCountService::class);

beforeEach(function (): void {
    Cache::flush();
});

it('creates stable fingerprints from count-relevant filters only', function (): void {
    $service = app(ListingCountService::class);

    $first = $service->fingerprint([
        'page' => 4,
        'per_page' => 10,
        'sort' => 'title',
        'direction' => 'asc',
        'type' => ['software', 'dataset'],
        'temporal' => ['yearTo' => 2025, 'yearFrom' => 2020],
    ]);
    $second = $service->fingerprint([
        'temporal' => ['yearFrom' => 2020, 'yearTo' => 2025],
        'type' => ['dataset', 'software'],
        'direction' => 'desc',
        'sort' => 'updated_at',
        'page' => 1,
        'per_page' => 100,
    ]);

    expect($first)->toBe($second)
        ->and($service->fingerprint(['type' => ['dataset']]))->not->toBe($first);
});

it('reuses a cached exact count for the same fingerprint', function (): void {
    $service = app(ListingCountService::class);
    $resolverCalls = 0;
    $resolver = function () use (&$resolverCalls): int {
        $resolverCalls++;

        return 42;
    };

    $first = $service->remember(CacheKey::RESOURCE_LISTING_COUNT, ['search' => 'climate'], $resolver);
    $second = $service->remember(CacheKey::RESOURCE_LISTING_COUNT, ['search' => 'climate'], $resolver);

    expect($first)->toBe(42)
        ->and($second)->toBe(42)
        ->and($resolverCalls)->toBe(1);
});
