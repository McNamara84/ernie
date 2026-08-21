<?php

declare(strict_types=1);

use App\Models\Right;
use App\Services\Crc806LegacyRightsService;
use App\Services\Spdx\SpdxLicenseLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(Crc806LegacyRightsService::class);

function createCrc806CatalogRight(
    string $identifier = 'CC-BY-NC-ND-4.0',
    string $uri = 'https://creativecommons.org/licenses/by-nc-nd/4.0',
    bool $active = true,
): Right {
    return Right::query()->create([
        'identifier' => $identifier,
        'name' => 'Catalog '.$identifier,
        'uri' => $uri,
        'scheme_uri' => SpdxLicenseLookup::SCHEME_URI,
        'is_active' => $active,
        'is_elmo_active' => true,
        'usage_count' => 0,
    ]);
}

/**
 * @param  array<int|string, mixed>|null  $extras
 */
function crc806LicensePage(
    string $doi = '10.5880/sfb806.80',
    string $name = 'CC BY-NC-ND',
    string $licenseUrl = 'https://creativecommons.org/licenses/by-nc-nd/4.0',
    ?array $extras = null,
): string {
    $routeInfo = [
        'allProps' => [
            'dataset' => [
                'title' => 'Data with a }; marker inside a JSON string',
                'extras' => $extras ?? ['bibtex:doi' => $doi],
                'license' => [
                    'name' => $name,
                    'url' => $licenseUrl,
                ],
            ],
        ],
    ];

    return '<!doctype html><script>window.__routeInfo = '
        .json_encode($routeInfo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        .';</script>';
}

it('imports the structured CRC806 license and canonicalizes the request URL', function (): void {
    createCrc806CatalogRight();
    $html = crc806LicensePage(extras: [[
        'key' => 'bibtex:doi',
        'value' => '@misc{record, doi = {https://doi.org/10.5880/SFB806.80}}',
    ]]);

    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => (string) strlen($html),
        ]),
    ]);
    Http::preventStrayRequests();

    $result = (new Crc806LegacyRightsService)->findRights(
        'https://doi.org/10.5880/sfb806.80',
        'http://CRC806DB.UNI-KOELN.DE:80/dataset/show/example/?view=full#license',
    );

    expect($result)->toBe([
        'rights' => 'CC BY-NC-ND',
        'rightsUri' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
        'rightsIdentifier' => 'CC-BY-NC-ND-4.0',
        'rightsIdentifierScheme' => 'SPDX',
        'schemeUri' => SpdxLicenseLookup::SCHEME_URI,
        'source' => 'legacy-crc806',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://crc806db.uni-koeln.de/dataset/show/example/?view=full'
        && $request->hasHeader('Accept', 'text/html,application/xhtml+xml'));
});

it('maps every supported Creative Commons family', function (string $name, string $uri, string $identifier): void {
    createCrc806CatalogRight($identifier, $uri);
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response(crc806LicensePage(name: $name, licenseUrl: $uri)),
    ]);

    $result = (new Crc806LegacyRightsService)->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/example/',
    );

    expect($result['rightsIdentifier'] ?? null)->toBe($identifier)
        ->and($result['rightsUri'] ?? null)->toBe($uri);
})->with([
    'attribution' => ['CC BY', 'https://creativecommons.org/licenses/by/4.0/', 'CC-BY-4.0'],
    'share alike' => ['CC BY-SA', 'https://creativecommons.org/licenses/by-sa/4.0', 'CC-BY-SA-4.0'],
    'no derivatives' => ['CC BY-ND', 'https://creativecommons.org/licenses/by-nd/4.0', 'CC-BY-ND-4.0'],
    'non-commercial' => ['CC BY-NC', 'https://creativecommons.org/licenses/by-nc/4.0', 'CC-BY-NC-4.0'],
    'non-commercial share alike' => ['CC BY-NC-SA', 'https://creativecommons.org/licenses/by-nc-sa/4.0', 'CC-BY-NC-SA-4.0'],
    'non-commercial no derivatives' => ['CC BY-NC-ND', 'https://creativecommons.org/licenses/by-nc-nd/4.0', 'CC-BY-NC-ND-4.0'],
    'public domain dedication' => ['CC0', 'https://creativecommons.org/publicdomain/zero/1.0/', 'CC0-1.0'],
]);

it('does not request an unsafe landing page URL', function (string $url): void {
    Http::fake();
    Http::preventStrayRequests();

    expect((new Crc806LegacyRightsService)->findRights('10.5880/sfb806.80', $url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'untrusted host' => ['https://example.org/dataset/show/example/'],
    'host suffix attack' => ['https://crc806db.uni-koeln.de.example.org/dataset/show/example/'],
    'host prefix attack' => ['https://crc806db.uni-koeln.de@evil.example/dataset/show/example/'],
    'userinfo' => ['https://user:secret@crc806db.uni-koeln.de/dataset/show/example/'],
    'unexpected port' => ['https://crc806db.uni-koeln.de:8443/dataset/show/example/'],
    'unsupported scheme' => ['ftp://crc806db.uni-koeln.de/dataset/show/example/'],
    'control characters' => ["https://crc806db.uni-koeln.de/dataset/\nshow/example/"],
]);

it('does not follow redirects, including redirects to foreign hosts', function (): void {
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response('', 302, [
            'Location' => 'https://169.254.169.254/latest/meta-data/',
        ]),
    ]);

    expect((new Crc806LegacyRightsService)->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/example/',
    ))->toBeNull();

    Http::assertSentCount(1);
});

it('rejects malformed or unverifiable structured data', function (string $html): void {
    createCrc806CatalogRight();
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response($html),
    ]);

    expect((new Crc806LegacyRightsService)->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/example/',
    ))->toBeNull();
})->with([
    'missing assignment' => ['<html><body>No route data</body></html>'],
    'invalid JSON' => ['<script>window.__routeInfo = {not: "json"};</script>'],
    'missing dataset' => ['<script>window.__routeInfo = {"allProps":{}};</script>'],
    'missing DOI extra' => [crc806LicensePage(extras: ['description' => 'No DOI'])],
    'different DOI' => [crc806LicensePage(doi: '10.5880/sfb806.81')],
    'ambiguous DOI extras' => [crc806LicensePage(extras: [
        'bibtex:doi' => '10.5880/sfb806.80',
        'doi' => '10.5880/sfb806.81',
    ])],
]);

it('does not guess an unsupported or conflicting license', function (string $name, string $uri): void {
    createCrc806CatalogRight();
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response(crc806LicensePage(name: $name, licenseUrl: $uri)),
    ]);

    expect((new Crc806LegacyRightsService)->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/example/',
    ))->toBeNull();
})->with([
    'unversioned URI' => ['CC BY-NC-ND', 'https://creativecommons.org/licenses/by-nc-nd/'],
    'unknown family' => ['CC Sampling Plus', 'https://creativecommons.org/licenses/sampling+/1.0/'],
    'localized URI' => ['CC BY', 'https://creativecommons.org/licenses/by/3.0/de/'],
    'URI with query' => ['CC BY', 'https://creativecommons.org/licenses/by/4.0/?lang=en'],
    'foreign URI host' => ['CC BY', 'https://creativecommons.org.example/licenses/by/4.0/'],
    'conflicting short name' => ['CC BY 4.0', 'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
    'conflicting version in short name' => ['CC BY-NC-ND 3.0', 'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
    'unrecognized CC-like short name' => ['CC BY NC ND', 'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
]);

it('requires an active matching SPDX catalog right', function (bool $createInactiveRight): void {
    if ($createInactiveRight) {
        createCrc806CatalogRight(active: false);
    }

    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::response(crc806LicensePage()),
    ]);

    expect((new Crc806LegacyRightsService)->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/example/',
    ))->toBeNull();
})->with([
    'missing right' => [false],
    'inactive right' => [true],
]);

it('keeps the provider available after a record-specific 404 response', function (): void {
    createCrc806CatalogRight();
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::sequence()
            ->push('Not found', 404)
            ->push(crc806LicensePage(), 200),
    ]);

    $service = new Crc806LegacyRightsService;

    expect($service->findRights(
        '10.5880/sfb806.80',
        'https://crc806db.uni-koeln.de/dataset/show/missing/',
    ))->toBeNull()
        ->and($service->findRights(
            '10.5880/sfb806.80',
            'https://crc806db.uni-koeln.de/dataset/show/example/',
        )['rightsIdentifier'] ?? null)->toBe('CC-BY-NC-ND-4.0');

    Http::assertSentCount(2);
});

it('retries server errors and opens the in-memory circuit breaker', function (): void {
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::sequence()
            ->push('Unavailable', 503)
            ->push('Unavailable', 503)
            ->push(crc806LicensePage(), 200),
    ]);

    $service = new Crc806LegacyRightsService;
    $url = 'https://crc806db.uni-koeln.de/dataset/show/example/';

    expect($service->findRights('10.5880/sfb806.80', $url))->toBeNull()
        ->and($service->findRights('10.5880/sfb806.80', $url))->toBeNull();

    Http::assertSentCount(2);
});

it('retries connection failures and opens the in-memory circuit breaker', function (): void {
    Http::fake(fn () => Http::failedConnection());

    $service = new Crc806LegacyRightsService;
    $url = 'https://crc806db.uni-koeln.de/dataset/show/example/';

    expect($service->findRights('10.5880/sfb806.80', $url))->toBeNull()
        ->and($service->findRights('10.5880/sfb806.80', $url))->toBeNull();

    Http::assertSentCount(2);
});

it('rejects responses whose declared or streamed body exceeds the size limit', function (): void {
    Http::fake([
        'https://crc806db.uni-koeln.de/*' => Http::sequence()
            ->push('short', 200, ['Content-Length' => '1000001'])
            ->push(str_repeat('x', 1_000_001), 200),
    ]);

    $url = 'https://crc806db.uni-koeln.de/dataset/show/example/';

    expect((new Crc806LegacyRightsService)->findRights('10.5880/sfb806.80', $url))->toBeNull()
        ->and((new Crc806LegacyRightsService)->findRights('10.5880/sfb806.80', $url))->toBeNull();

    Http::assertSentCount(2);
});
