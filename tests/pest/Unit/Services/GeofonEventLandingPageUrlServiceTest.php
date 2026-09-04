<?php

declare(strict_types=1);

use App\Services\GeofonEventLandingPageUrlService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(GeofonEventLandingPageUrlService::class);

it('maps every supported legacy GEOFON event URL to the canonical target', function (string $url): void {
    $inspection = app(GeofonEventLandingPageUrlService::class)->inspect($url);

    expect($inspection)->toMatchArray([
        'status' => 'legacy',
        'recognized_host' => true,
        'event_id' => 'gfz2011axdw',
        'target_url' => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw',
        'needs_update' => true,
        'message' => null,
    ]);
})->with([
    'canonical host over HTTP' => ['http://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw'],
    'canonical host over HTTPS' => ['https://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw'],
    'legacy host over HTTP' => ['http://geofon.gfz-potsdam.de/db/eqpage.php?id=gfz2011axdw'],
    'legacy host over HTTPS' => ['https://geofon.gfz-potsdam.de/db/eqpage.php?id=gfz2011axdw'],
]);

it('distinguishes canonical and non-canonical current event URLs', function (): void {
    $service = app(GeofonEventLandingPageUrlService::class);

    expect($service->inspect('https://geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx'))
        ->toMatchArray([
            'status' => 'current',
            'event_id' => 'gfz2010gtdx',
            'needs_update' => false,
        ])
        ->and($service->inspect('http://geofon.gfz.de/eqinfo/event.php?id=GFZ2010GTDX'))
        ->toMatchArray([
            'status' => 'current',
            'event_id' => 'gfz2010gtdx',
            'needs_update' => true,
            'target_url' => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx',
        ]);
});

it('does not ignore user information when comparing URLs', function (): void {
    $target = 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx';

    expect(app(GeofonEventLandingPageUrlService::class)->urlsEqual(
        'https://user@geofon.gfz.de/eqinfo/event.php?id=gfz2010gtdx',
        $target,
    ))->toBeFalse();
});

it('rejects unsafe or unsupported GEOFON event URLs', function (string $url, string $status, bool $recognizedHost): void {
    expect(app(GeofonEventLandingPageUrlService::class)->inspect($url))
        ->toMatchArray([
            'status' => $status,
            'recognized_host' => $recognizedHost,
            'event_id' => null,
            'target_url' => null,
            'needs_update' => false,
        ]);
})->with([
    'empty' => ['', 'invalid', false],
    'relative' => ['/db/eqpage.php?id=gfz2011axdw', 'invalid', false],
    'non HTTP' => ['ftp://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw', 'invalid', false],
    'foreign host' => ['https://example.org/db/eqpage.php?id=gfz2011axdw', 'unknown', false],
    'unknown path' => ['https://geofon.gfz.de/db/other.php?id=gfz2011axdw', 'unknown', true],
    'additional query' => ['https://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw&lang=en', 'invalid', true],
    'empty id' => ['https://geofon.gfz.de/db/eqpage.php?id=', 'invalid', true],
    'fragment' => ['https://geofon.gfz.de/db/eqpage.php?id=gfz2011axdw#map', 'invalid', true],
    'port' => ['https://geofon.gfz.de:443/db/eqpage.php?id=gfz2011axdw', 'invalid', true],
    'userinfo' => ['https://user@geofon.gfz.de/db/eqpage.php?id=gfz2011axdw', 'invalid', true],
]);

it('extracts event IDs only from supported GEOFON event DOI namespaces', function (string $doi, ?string $eventId): void {
    expect(app(GeofonEventLandingPageUrlService::class)->eventIdFromDoi($doi))->toBe($eventId);
})->with([
    'legacy namespace' => ['10.1594/GFZ.GEOFON.GFZ2011AXDW', 'gfz2011axdw'],
    'current namespace' => ['10.5880/GEOFON.GFZ2015ICRA', 'gfz2015icra'],
    'network DOI' => ['10.14470/rv968923', null],
    'malformed event ID' => ['10.1594/gfz.geofon.event', null],
    'trailing suffix' => ['10.1594/gfz.geofon.gfz2011axdw.extra', null],
]);

it('accepts a reachable canonical target', function (): void {
    Http::fake(['geofon.gfz.de/*' => Http::response('', 200)]);

    $result = app(GeofonEventLandingPageUrlService::class)
        ->probe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw');

    expect($result)->toMatchArray([
        'reachable' => true,
        'http_status' => 200,
        'effective_url' => 'https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw',
        'message' => null,
    ]);
    Http::assertSentCount(1);
});

it('falls back to a bounded GET when GEOFON rejects HEAD', function (): void {
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 405)
        : Http::response('', 206));

    $result = app(GeofonEventLandingPageUrlService::class)
        ->probe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw');

    expect($result['reachable'])->toBeTrue()
        ->and($result['http_status'])->toBe(206);
    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all())
        ->toBe(['HEAD', 'GET']);
});

it('reports target HTTP and connection failures without throwing', function (): void {
    Http::fake(['geofon.gfz.de/*' => Http::response('', 503)]);
    $service = app(GeofonEventLandingPageUrlService::class);

    expect($service->probe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw'))
        ->toMatchArray(['reachable' => false, 'http_status' => 503]);

    Http::fake(['geofon.gfz.de/*' => Http::failedConnection('timed out')]);

    expect($service->probe('https://geofon.gfz.de/eqinfo/event.php?id=gfz2011axdw'))
        ->toMatchArray([
            'reachable' => false,
            'http_status' => null,
            'effective_url' => null,
        ]);
});
