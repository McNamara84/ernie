<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Services\DataCiteLandingPageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('parses external DataCite URLs into domain and path', function (): void {
    $parts = (new DataCiteLandingPageImportService)->parseExternalUrl(
        'https://geofon.gfz.de/waveform/archive/network.php?ncode=_EIFELLNX'
    );

    expect($parts)->toBe([
        'domain' => 'https://geofon.gfz.de/',
        'path' => 'waveform/archive/network.php?ncode=_EIFELLNX',
    ]);
});

it('creates a published external landing page for findable DataCite records', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.14470/rv968923']);

    $result = (new DataCiteLandingPageImportService)->createExternalForResource($resource, [
        'url' => 'https://geofon.gfz.de/waveform/archive/network.php?ncode=_EIFELLNX',
        'state' => 'findable',
    ]);

    $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;

    expect($result['changed'])->toBeTrue()
        ->and($result['created'])->toBeTrue()
        ->and($landingPage)->not->toBeNull()
        ->and($landingPage->template)->toBe('external')
        ->and($landingPage->ftp_url)->toBeNull()
        ->and($landingPage->is_published)->toBeTrue()
        ->and($landingPage->published_at)->not->toBeNull()
        ->and($landingPage->externalDomain->domain)->toBe('https://geofon.gfz.de/')
        ->and($landingPage->external_path)->toBe('waveform/archive/network.php?ncode=_EIFELLNX')
        ->and($landingPage->public_url)->toBe('https://geofon.gfz.de/waveform/archive/network.php?ncode=_EIFELLNX');
});

it('creates a draft external landing page for non-findable DataCite records', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.14470/draft']);

    (new DataCiteLandingPageImportService)->createExternalForResource($resource, [
        'url' => 'https://geofon.gfz.de/waveform/archive/network.php?ncode=DRAFT',
        'state' => 'draft',
    ]);

    $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;

    expect($landingPage)->not->toBeNull()
        ->and($landingPage->is_published)->toBeFalse()
        ->and($landingPage->published_at)->toBeNull()
        ->and($landingPage->external_url)->toBe('https://geofon.gfz.de/waveform/archive/network.php?ncode=DRAFT');
});

it('does not overwrite an existing landing page', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.14470/existing']);
    $existing = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'ftp_url' => 'https://datapub.gfz.de/existing.zip',
    ]);

    $result = (new DataCiteLandingPageImportService)->createExternalForResource($resource, [
        'url' => 'https://geofon.gfz.de/waveform/archive/network.php?ncode=EXISTING',
        'state' => 'findable',
    ]);

    expect($result['changed'])->toBeFalse()
        ->and(LandingPage::where('resource_id', $resource->id)->count())->toBe(1)
        ->and($existing->fresh()->template)->toBe('default_gfz')
        ->and($existing->fresh()->ftp_url)->toBe('https://datapub.gfz.de/existing.zip');
});

it('ignores empty and non-http DataCite URLs', function (?string $url): void {
    $resource = Resource::factory()->create();

    $result = (new DataCiteLandingPageImportService)->createExternalForResource($resource, [
        'url' => $url,
        'state' => 'findable',
    ]);

    expect($result['changed'])->toBeFalse()
        ->and(LandingPage::where('resource_id', $resource->id)->exists())->toBeFalse()
        ->and(LandingPageDomain::count())->toBe(0);
})->with([
    'empty' => [''],
    'null' => [null],
    'ftp' => ['ftp://geofon.gfz.de/network'],
    'relative' => ['/waveform/archive/network.php?ncode=_EIFELLNX'],
]);
