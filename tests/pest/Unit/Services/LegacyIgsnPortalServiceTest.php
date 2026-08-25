<?php

declare(strict_types=1);

use App\Exceptions\LegacyIgsnPortalException;
use App\Models\Datacenter;
use App\Services\LegacyIgsnPortalService;
use App\Support\LegacyIgsnDatacenterCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

covers(LegacyIgsnPortalService::class, LegacyIgsnDatacenterCatalog::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('datacite.production.igsn_prefix', '10.60510');
    Config::set('datacite.legacy_igsn_portal', [
        'proxy_url' => 'https://igsn-portal.example.test/proxy.php',
        'timeout_seconds' => 5,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'retry_jitter_ms' => 0,
        'page_size' => 2,
        'datacenter_cache_ttl_seconds' => 600,
    ]);
});

function legacyIgsnFacetResponse(array $facets): array
{
    return [
        'facet_counts' => [
            'facet_fields' => [
                'datacentre_facet' => $facets,
            ],
        ],
    ];
}

it('loads normalizes sorts and caches the known IGSN datacenters', function (): void {
    $log = Log::spy();

    Http::fake([
        'igsn-portal.example.test/*' => Http::response(legacyIgsnFacetResponse([
            'IGSNDB.SO273 - Sonne273' => 432,
            'IGSNDB.GFZ - GFZ Potsdam' => 222,
            'IGSNDB.UNKNOWN - Unknown' => 99,
            'IGSNDB.AWIENV - AWI: Polar Terrestrial Environmental Systems' => 2056,
        ])),
    ]);

    $service = app(LegacyIgsnPortalService::class);

    expect($service->listDatacenters())->toBe([
        [
            'id' => 'IGSNDB.AWIENV',
            'legacy_name' => 'AWI: Polar Terrestrial Environmental Systems',
            'name' => 'AWI: Polar Terrestrial Environmental Systems',
            'resource_count' => 2056,
        ],
        [
            'id' => 'IGSNDB.GFZ',
            'legacy_name' => 'GFZ Potsdam',
            'name' => Datacenter::GFZ_NAME,
            'resource_count' => 222,
        ],
        [
            'id' => 'IGSNDB.SO273',
            'legacy_name' => 'Sonne273',
            'name' => 'Sonne273',
            'resource_count' => 432,
        ],
    ])->and($service->listDatacenters())->toHaveCount(3);

    Http::assertSentCount(1);
    $log->shouldHaveReceived('warning')
        ->once()
        ->with('Skipping unknown legacy IGSN datacenter facet', [
            'facet_value' => 'IGSNDB.UNKNOWN - Unknown',
            'resource_count' => 99,
        ]);
    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return $request->method() === 'POST'
            && $query['facet_field'] === 'datacentre_facet'
            && $query['rows'] === '0';
    });
});

it('defines the complete canonical datacenter catalog without a GFZ Potsdam record', function (): void {
    expect(LegacyIgsnDatacenterCatalog::all())->toHaveCount(9)
        ->and(LegacyIgsnDatacenterCatalog::canonicalNames())->toContain(
            'AWI: Polar Terrestrial Environmental Systems',
            'Earth Shape SPP',
            'Expedition database Hereon',
            'Geothermal Energy Systems',
            Datacenter::GFZ_NAME,
            'High Latitude Lakes',
            'ICDP',
            'Medusa',
            'Sonne273',
        )
        ->not->toContain('GFZ Potsdam');
});

it('paginates a datacenter selection and normalizes old identifiers', function (): void {
    Http::fakeSequence('igsn-portal.example.test/*')
        ->push(legacyIgsnFacetResponse([
            'IGSNDB.GFZ - GFZ Potsdam' => 3,
        ]))
        ->push([
            'response' => [
                'numFound' => 3,
                'docs' => [
                    [
                        'igsn' => 'GFZAA001',
                        'doi' => '10273/GFZAA001',
                        'datacentre_facet' => 'IGSNDB.GFZ - GFZ Potsdam',
                    ],
                    [
                        'igsn' => 'GFZAA002',
                        'doi' => '10273/GFZAA002',
                        'datacentre_facet' => 'IGSNDB.GFZ - GFZ Potsdam',
                    ],
                ],
            ],
        ])
        ->push([
            'response' => [
                'numFound' => 3,
                'docs' => [
                    [
                        'doi' => '10273/GFZAA003',
                        'datacentre_facet' => 'IGSNDB.GFZ - GFZ Potsdam',
                    ],
                ],
            ],
        ]);

    $result = app(LegacyIgsnPortalService::class)->igsnsForDatacenter('IGSNDB.GFZ');

    expect($result['datacenter']['name'])->toBe(Datacenter::GFZ_NAME)
        ->and($result['dois'])->toBe([
            '10.60510/gfzaa001',
            '10.60510/gfzaa002',
            '10.60510/gfzaa003',
        ]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return ($query['fq'] ?? null) === 'datacentre_facet:"IGSNDB.GFZ - GFZ Potsdam"'
            && ($query['sort'] ?? null) === 'igsn asc';
    });
});

it('builds assignments for all IGSNs and for selected handles', function (): void {
    Config::set('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 0);

    Http::fakeSequence('igsn-portal.example.test/*')
        ->push([
            'response' => [
                'numFound' => 2,
                'docs' => [
                    [
                        'igsn' => 'ICDP001',
                        'datacentre_facet' => 'IGSNDB.ICDP - ICDP',
                    ],
                    [
                        'igsn' => 'GFZ001',
                        'datacentre_facet' => 'IGSNDB.GFZ - GFZ Potsdam',
                    ],
                ],
            ],
        ])
        ->push([
            'response' => [
                'numFound' => 1,
                'docs' => [
                    [
                        'igsn' => 'ICDP001',
                        'datacentre_facet' => 'IGSNDB.ICDP - ICDP',
                    ],
                ],
            ],
        ]);

    $service = app(LegacyIgsnPortalService::class);

    expect($service->assignmentsForAllIgsns())->toBe([
        '10.60510/gfz001' => Datacenter::GFZ_NAME,
        '10.60510/icdp001' => 'ICDP',
    ])->and($service->assignmentsForHandles(['icdp001', 'invalid handle']))->toBe([
        '10.60510/icdp001' => 'ICDP',
    ]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return str_contains((string) ($query['q'] ?? ''), 'igsn:("ICDP001")');
    });
});

it('rejects an unavailable datacenter and incomplete pagination', function (): void {
    Http::fakeSequence('igsn-portal.example.test/*')
        ->push(legacyIgsnFacetResponse([
            'IGSNDB.ICDP - ICDP' => 1,
        ]))
        ->push([
            'response' => [
                'numFound' => 2,
                'docs' => [],
            ],
        ]);

    $service = app(LegacyIgsnPortalService::class);

    expect(fn () => $service->igsnsForDatacenter('IGSNDB.UNKNOWN'))
        ->toThrow(RuntimeException::class, 'no longer available');

    expect(fn () => $service->igsnsForDatacenter('IGSNDB.ICDP'))
        ->toThrow(RuntimeException::class, 'pagination ended unexpectedly');
});

it('fails safely for insecure failed malformed and unreachable portal responses', function (): void {
    Config::set('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 0);
    Config::set('datacite.legacy_igsn_portal.proxy_url', 'http://igsn-portal.example.test/proxy.php');

    expect(fn () => app(LegacyIgsnPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'must use HTTPS');

    Http::assertNothingSent();

    Config::set('datacite.legacy_igsn_portal.proxy_url', 'https://igsn-portal.example.test/proxy.php');
    Http::fakeSequence('igsn-portal.example.test/*')
        ->push(['message' => 'unavailable'], 503)
        ->push(['facet_counts' => []]);

    expect(fn () => app(LegacyIgsnPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'HTTP 503');

    expect(fn () => app(LegacyIgsnPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'invalid datacenter response');
});

it('hides connection details when the legacy portal is unreachable', function (): void {
    Config::set('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 0);
    Config::set('datacite.legacy_igsn_portal.proxy_url', 'https://igsn-portal.example.test/proxy.php');

    Http::fake(function (): never {
        throw new ConnectionException('sensitive transport details');
    });

    expect(fn () => app(LegacyIgsnPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'could not be reached');
});

it('retries a successful HTTP response containing invalid JSON', function (): void {
    Config::set('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 0);
    Config::set('datacite.legacy_igsn_portal.retry_times', 2);
    Config::set('datacite.legacy_igsn_portal.retry_sleep_ms', 0);
    Log::spy();

    Http::fakeSequence('igsn-portal.example.test/*')
        ->push('temporary proxy output', 200, ['Content-Type' => 'text/html'])
        ->push(legacyIgsnFacetResponse([
            'IGSNDB.GFZ - GFZ Potsdam' => 1,
        ]));

    expect(app(LegacyIgsnPortalService::class)->listDatacenters())
        ->toHaveCount(1)
        ->and(Http::recorded())->toHaveCount(2);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Retrying legacy IGSN portal request', [
            'attempt' => 1,
            'max_attempts' => 2,
            'failure_code' => LegacyIgsnPortalException::INVALID_PAYLOAD,
        ]);
});

it('retries an incomplete Solr response before accepting a valid document list', function (): void {
    Config::set('datacite.legacy_igsn_portal.retry_times', 2);

    Http::fakeSequence('igsn-portal.example.test/*')
        ->push(['response' => ['numFound' => 1]])
        ->push([
            'response' => [
                'numFound' => 1,
                'docs' => [[
                    'igsn' => 'GFRETRIED001',
                    'has_dif' => false,
                ]],
            ],
        ]);

    expect(app(LegacyIgsnPortalService::class)->difForHandles(['GFRETRIED001']))
        ->toBe(['GFRETRIED001' => null])
        ->and(Http::recorded())->toHaveCount(2);
});

it('classifies persistent invalid JSON and unexpected content types as invalid payloads', function (string $body, string $contentType): void {
    Config::set('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 0);
    Config::set('datacite.legacy_igsn_portal.retry_times', 2);

    Http::fake([
        'igsn-portal.example.test/*' => Http::response($body, 200, ['Content-Type' => $contentType]),
    ]);

    try {
        app(LegacyIgsnPortalService::class)->listDatacenters();
        throw new RuntimeException('Expected the invalid portal response to fail.');
    } catch (LegacyIgsnPortalException $exception) {
        expect($exception->failureCode)->toBe(LegacyIgsnPortalException::INVALID_PAYLOAD)
            ->and($exception->retryable)->toBeTrue()
            ->and(Http::recorded())->toHaveCount(2);
    }
})->with([
    'invalid JSON' => ['not-json', 'text/html; charset=UTF-8'],
    'unexpected content type' => ['<xml/>', 'application/xml'],
]);

it('loads decoded DIF metadata and reports authoritative missing DIF values', function (): void {
    $difXml = '<DIF><sample><sample_type>Rock</sample_type></sample></DIF>';

    Http::fake([
        'igsn-portal.example.test/*' => Http::response([
            'response' => [
                'numFound' => 2,
                'docs' => [
                    [
                        'igsn' => 'GFWITHDIF001',
                        'has_dif' => true,
                        'dif' => base64_encode($difXml),
                    ],
                    [
                        'igsn' => 'GFNODIF001',
                        'has_dif' => false,
                    ],
                ],
            ],
        ]),
    ]);

    expect(app(LegacyIgsnPortalService::class)->difForHandles([
        'gfwithdif001',
        'GFNODIF001',
        'invalid handle',
    ]))->toBe([
        'GFWITHDIF001' => $difXml,
        'GFNODIF001' => null,
    ]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return ($query['wt'] ?? null) === 'json'
            && ($query['fl'] ?? null) === 'igsn,dif,has_dif';
    });
});

it('rejects a portal document with an invalid encoded DIF payload', function (): void {
    Http::fake([
        'igsn-portal.example.test/*' => Http::response([
            'response' => [
                'numFound' => 1,
                'docs' => [[
                    'igsn' => 'GFBADDIF001',
                    'has_dif' => true,
                    'dif' => 'not valid base64 %%%',
                ]],
            ],
        ]),
    ]);

    expect(fn () => app(LegacyIgsnPortalService::class)->difForHandles(['GFBADDIF001']))
        ->toThrow(LegacyIgsnPortalException::class, 'invalid DIF metadata');
});
