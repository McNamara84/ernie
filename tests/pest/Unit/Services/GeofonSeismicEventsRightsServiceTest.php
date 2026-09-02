<?php

declare(strict_types=1);

use App\Models\Right;
use App\Services\GeofonSeismicEventsRightsService;
use App\Services\LegacyMetaworksDatacenterLookupService;

it('builds the GEOFON seismic-event default from the SPDX catalog', function (): void {
    $cc0 = Right::factory()->cc0()->create();
    $service = new GeofonSeismicEventsRightsService;

    expect($service->rightsStatementForImport('https://doi.org/10.1594/GFZ.GEOFON.EVENT'))
        ->toBe([
            'rights' => $cc0->name,
            'rightsUri' => $cc0->uri,
            'rightsIdentifier' => 'CC0-1.0',
            'rightsIdentifierScheme' => 'SPDX',
            'schemeUri' => 'https://spdx.org/licenses/',
            'source' => 'geofon-seismic-events-default',
        ]);
});

it('uses the datacenter assignment when the DOI does not identify the collection', function (): void {
    Right::factory()->cc0()->create();
    $service = new GeofonSeismicEventsRightsService;

    expect($service->rightsStatementForImport(
        '10.1234/example',
        [strtolower(LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER)],
    ))->not->toBeNull();
});

it('recognizes current 10.5880 GEOFON seismic-event DOIs', function (): void {
    Right::factory()->cc0()->create();
    $service = new GeofonSeismicEventsRightsService;

    expect($service->rightsStatementForImport('10.5880/GEOFON.GFZ2015ICRA'))
        ->not->toBeNull();
});

it('does not add CC0 to other GEOFON collections', function (): void {
    $service = new GeofonSeismicEventsRightsService;

    expect($service->rightsStatementForImport(
        '10.14470/rv968923',
        [LegacyMetaworksDatacenterLookupService::GEOFON_NETWORKS_DATACENTER],
    ))->toBeNull();
});

it('fails instead of creating a custom license when CC0 is missing from the SPDX catalog', function (): void {
    $service = new GeofonSeismicEventsRightsService;

    expect(fn () => $service->rightsStatementForImport('10.1594/gfz.geofon.event'))
        ->toThrow(RuntimeException::class, 'The SPDX catalog license CC0-1.0 is required');
});
