<?php

declare(strict_types=1);

use App\Services\GfzDataServicesPortalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(GfzDataServicesPortalService::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('datacite.legacy_portal', [
        'proxy_url' => 'https://portal.example.test/proxy.php',
        'timeout_seconds' => 5,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'page_size' => 2,
        'datacenter_cache_ttl_seconds' => 600,
    ]);
});

function portalFacetResponse(array $facets): array
{
    return [
        'facet_counts' => [
            'facet_fields' => [
                'datacentre_facet' => $facets,
            ],
        ],
    ];
}

it('loads sorts and caches datacenters from portal facets', function (): void {
    Http::fake([
        'portal.example.test/*' => Http::response(portalFacetResponse([
            'DOIDB.RIESGOS - Riesgos' => 15,
            'invalid-facet' => 99,
            'DOIDB.ARBODAT - ArboDat 2016' => 172,
        ])),
    ]);

    $service = app(GfzDataServicesPortalService::class);

    expect($service->listDatacenters())->toBe([
        [
            'id' => 'DOIDB.ARBODAT',
            'name' => 'ArboDat 2016',
            'resource_count' => 172,
        ],
        [
            'id' => 'DOIDB.RIESGOS',
            'name' => 'Riesgos',
            'resource_count' => 15,
        ],
    ])->and($service->listDatacenters())->toHaveCount(2);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return $request->method() === 'POST'
            && $request->url() === 'https://portal.example.test/proxy.php'
            && $query['facet_field'] === 'datacentre_facet'
            && $query['rows'] === '0';
    });
});

it('paginates resources and keeps all portal datacenter assignments', function (): void {
    Http::fakeSequence('portal.example.test/*')
        ->push(portalFacetResponse([
            'DOIDB.RIESGOS - Riesgos' => 3,
            'DOIDB.GFZ - GFZ German Research Centre for Geosciences' => 755,
        ]))
        ->push([
            'response' => [
                'numFound' => 3,
                'docs' => [
                    [
                        'doi' => '10.5880/RIESGOS.2021.001',
                        'datacentre_facet' => 'DOIDB.RIESGOS - Riesgos',
                    ],
                    [
                        'doi' => 'https://doi.org/10.5880/RIESGOS.2021.002',
                        'datacentre_facet' => [
                            'DOIDB.RIESGOS - Riesgos',
                            'DOIDB.GFZ - GFZ German Research Centre for Geosciences',
                        ],
                    ],
                ],
            ],
        ])
        ->push([
            'response' => [
                'numFound' => 3,
                'docs' => [
                    [
                        'doi' => '10.5880/riesgos.2021.001',
                        'datacentre_facet' => 'DOIDB.RIESGOS - Riesgos',
                    ],
                    [
                        'doi' => '10.5880/RIESGOS.2021.003',
                    ],
                ],
            ],
        ]);

    $result = app(GfzDataServicesPortalService::class)
        ->resourcesForDatacenter('DOIDB.RIESGOS');

    expect($result['datacenter']['name'])->toBe('Riesgos')
        ->and($result['resources'])->toBe([
            '10.5880/riesgos.2021.001' => ['Riesgos'],
            '10.5880/riesgos.2021.002' => [
                'Riesgos',
                'GFZ German Research Centre for Geosciences',
            ],
            '10.5880/riesgos.2021.003' => ['Riesgos'],
        ]);

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request): bool {
        parse_str((string) $request->data()['query'], $query);

        return isset($query['fq'])
            && $query['fq'] === 'datacentre_facet:"DOIDB.RIESGOS - Riesgos" AND -type:text'
            && $query['sort'] === 'doi asc';
    });
});

it('rejects a datacenter id that is not in the portal list', function (): void {
    Http::fake([
        'portal.example.test/*' => Http::response(portalFacetResponse([
            'DOIDB.RIESGOS - Riesgos' => 15,
        ])),
    ]);

    expect(fn () => app(GfzDataServicesPortalService::class)
        ->resourcesForDatacenter('DOIDB.UNKNOWN'))
        ->toThrow(RuntimeException::class, 'no longer available');

    Http::assertSentCount(1);
});

it('fails safely for an insecure proxy url', function (): void {
    Config::set('datacite.legacy_portal.proxy_url', 'http://portal.example.test/proxy.php');
    Config::set('datacite.legacy_portal.datacenter_cache_ttl_seconds', 0);

    expect(fn () => app(GfzDataServicesPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'must use HTTPS');

    Http::assertNothingSent();
});

it('fails safely for unsuccessful and malformed portal responses', function (): void {
    Config::set('datacite.legacy_portal.datacenter_cache_ttl_seconds', 0);

    Http::fakeSequence('portal.example.test/*')
        ->push(['message' => 'unavailable'], 503)
        ->push(['facet_counts' => []]);

    expect(fn () => app(GfzDataServicesPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'HTTP 503');

    expect(fn () => app(GfzDataServicesPortalService::class)->listDatacenters())
        ->toThrow(RuntimeException::class, 'invalid datacenter response');
});

it('converts connection failures into a stable portal error', function (): void {
    Config::set('datacite.legacy_portal.datacenter_cache_ttl_seconds', 0);

    Http::fake(function (): never {
        throw new ConnectionException('cURL error containing upstream details');
    });

    expect(fn () => app(GfzDataServicesPortalService::class)->listDatacenters())
        ->toThrow(
            RuntimeException::class,
            'The GFZ Data Services portal could not be reached.',
        );
});
