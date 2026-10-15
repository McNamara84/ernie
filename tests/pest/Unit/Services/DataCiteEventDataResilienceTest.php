<?php

declare(strict_types=1);

use App\Services\DataCiteEventDataService;
use App\Services\DataCitePublicApiLimiterService;
use App\Services\RelationTypeCorrection\RelationMetadataClientService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('shares provider cooldowns between assistant modules without blocking Crossref', function () {
    Http::fake([
        'api.datacite.org/*' => Http::response([], 503),
        '*' => Http::response(['message' => ['DOI' => '10.5880/other']], 200),
    ]);
    app(DataCiteEventDataService::class)->findRelationsForDoi('10.5880/first');
    $metadata = app(RelationMetadataClientService::class);
    expect($metadata->record('datacite', '10.5880/other')['status'])->toBe('unavailable')
        ->and($metadata->record('crossref', '10.5880/other')['status'])->toBe('ok');
    Http::assertSentCount(2);
});

it('does not consume a public permit or call the provider when its reservation lock is busy', function () {
    $lock = Cache::lock('datacite:public-discovery:lock', 5);
    expect($lock->get())->toBeTrue();
    try {
        expect(app(DataCitePublicApiLimiterService::class)->tryAcquire())->toBeFalse();
    } finally {
        $lock->release();
    }
    expect(app(DataCitePublicApiLimiterService::class)->tryAcquire())->toBeTrue();
});

it('stops calling an unavailable Event Data provider across service instances', function (int $status, string $retryAfter) {
    $retryAfter = $retryAfter === 'http-date' ? now()->addMinute()->toRfc7231String() : $retryAfter;
    Http::fakeSequence()->push([], $status, ['Retry-After' => $retryAfter])->push(['data' => []], 200);
    app(DataCiteEventDataService::class)->findRelationsForDoi('10.5880/first');
    for ($index = 0; $index < 100; $index++) {
        (new DataCiteEventDataService)->findRelationsForDoi('10.5880/next-'.$index);
    }
    Http::assertSentCount(1);
    $this->travel(61)->seconds();
    app(DataCiteEventDataService::class)->findRelationsForDoi('10.5880/recovered');
    Http::assertSentCount(2);
})->with([
    'rate limit in seconds' => [429, '60'],
    'service error with invalid header' => [503, 'invalid'],
    'HTTP-date retry after' => [429, 'http-date'],
]);

it('backs off after network errors rather than repeating them for every DOI', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('timeout');
    });
    app(DataCiteEventDataService::class)->findRelationsForDoi('10.5880/first');
    app(DataCiteEventDataService::class)->findRelationsForDoi('10.5880/second');
    expect(Cache::has('datacite:public-discovery:unavailable'))->toBeTrue()->and($calls)->toBe(1);
});

it('caps the public request budget while leaving unrelated services available', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $service = app(DataCiteEventDataService::class);
    for ($index = 0; $index < 301; $index++) {
        $service->findRelationsForDoi('10.5880/item-'.$index);
    }
    Http::assertSentCount(300);
    expect(Cache::has('datacite:public-discovery:unavailable'))->toBeTrue();
});
