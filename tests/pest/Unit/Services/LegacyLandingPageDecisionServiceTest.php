<?php

declare(strict_types=1);

use App\Services\LegacyLandingPageDecisionService;

it('skips legacy dois marked as test or delete', function (string $doi): void {
    $service = new LegacyLandingPageDecisionService;

    expect($service->shouldSkipLegacyDoi($doi))->toBeTrue();
})->with([
    'test suffix' => ['10.5880/fidgeo.test.to.be.deleted'],
    'delete suffix' => ['10.5880/GFZ.DELETE.001'],
    'hyphen separated delete marker' => ['10.5880/gfz-delete-001'],
    'underscore separated test marker' => ['10.5880/gfz_test_001'],
]);

it('does not skip ordinary legacy dois', function (string $doi): void {
    $service = new LegacyLandingPageDecisionService;

    expect($service->shouldSkipLegacyDoi($doi))->toBeFalse();
})->with([
    'ordinary GFZ DOI' => ['10.5880/gfz.2.6.2023.010'],
    'latest contains test as substring' => ['10.5880/latest.2026.001'],
    'contest contains test as substring' => ['10.5880/contest.dataset.001'],
    'laTEST contains test as substring with mixed case' => ['10.5880/laTEST.001'],
    'deleted is not a delete marker segment' => ['10.5880/to.be.deleted'],
]);

it('recognizes old Data Services runtime URLs', function (string $url): void {
    $service = new LegacyLandingPageDecisionService;

    expect($service->isLegacyDataServicesUrl($url))->toBeTrue();
})->with([
    'current old runtime host' => ['https://dataservices.gfz.de/panmetaworks/showshort.php?id=abc'],
    'legacy potsdam host' => ['http://dataservices.gfz-potsdam.de/dekorp/showshort.php?id=abc'],
]);

it('allows GEOFON 10.14470 DataCite URLs as external landing pages', function (): void {
    $service = new LegacyLandingPageDecisionService;

    expect($service->shouldImportDataCiteUrlAsExternal('10.14470/9i763612', [
        'url' => 'https://geofon.gfz.de/doi/network/4V/2022',
    ]))->toBeTrue();
});

it('rejects non-GEOFON and old Data Services DataCite URLs as external landing pages', function (string $doi, string $url): void {
    $service = new LegacyLandingPageDecisionService;

    expect($service->shouldImportDataCiteUrlAsExternal($doi, ['url' => $url]))->toBeFalse();
})->with([
    'old data services runtime URL' => [
        '10.5880/gfz.2.6.2023.010',
        'https://dataservices.gfz.de/panmetaworks/showshort.php?id=d9d1cfb5-7a4f-11ee-967a-4ffbfe06208e',
    ],
    'geofon host with wrong prefix' => [
        '10.5880/gfz.2.6.2023.010',
        'https://geofon.gfz.de/doi/network/4V/2022',
    ],
    'other external host' => [
        '10.5880/gfz.2.6.2023.010',
        'https://example.org/dataset',
    ],
]);
