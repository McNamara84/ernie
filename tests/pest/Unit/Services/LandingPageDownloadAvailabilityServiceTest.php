<?php

use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Services\LandingPageDownloadAvailabilityService;
use Illuminate\Database\Eloquent\Collection;

covers(LandingPageDownloadAvailabilityService::class);

it('recognizes usable primary URLs without fetching them', function (mixed $url, bool $expected) {
    expect((new LandingPageDownloadAvailabilityService)->hasSources($url, []))->toBe($expected);
})->with([
    [null, false], ['', false], ['  ', false], ['#', false], ['javascript:alert(1)', false],
    ['ftp://example.org/data', false], ['https://', false], ['relative/file', false],
    ['https://example.org/download', true], [' http://example.org/data.zip ', true],
]);

it('recognizes usable imported files in model and preview representations', function () {
    $service = new LandingPageDownloadAvailabilityService;
    expect($service->hasSources(null, [['url' => '#'], ['url' => 'https://example.org/data.zip']]))->toBeTrue()
        ->and($service->hasSources(null, [new LandingPageFile(['url' => 'https://example.org/data.zip'])]))->toBeTrue()
        ->and($service->hasSources(null, [['url' => '  '], []]))->toBeFalse();
});

it('preserves historical suppression for retained values including additional links', function (string $source) {
    $page = new LandingPage(['downloads_unavailable' => true, 'ftp_url' => $source === 'primary' ? '#' : null]);
    $page->setRelation('files', new Collection($source === 'file' ? [new LandingPageFile(['url' => 'historical-value'])] : []));
    $page->setRelation('links', new Collection($source === 'link' ? [new LandingPageLink(['url' => 'https://example.org/repo'])] : []));
    $service = new LandingPageDownloadAvailabilityService;

    $service->normalizeEmptySuppression($page);
    expect($service->requiresActivation($page))->toBeTrue()->and($page->downloads_unavailable)->toBeTrue();
})->with(['primary', 'file', 'link']);

it('normalizes empty historical flags before applying the first URL', function () {
    $page = new LandingPage(['downloads_unavailable' => true, 'ftp_url' => ' ']);
    $page->setRelation('files', new Collection);
    $page->setRelation('links', new Collection);
    $service = new LandingPageDownloadAvailabilityService;

    expect($service->requiresActivation($page))->toBeFalse();
    $service->normalizeEmptySuppression($page);
    $page->ftp_url = 'https://example.org/new.zip';
    expect($service->requiresActivation($page))->toBeFalse()->and($page->downloads_unavailable)->toBeFalse();
});
