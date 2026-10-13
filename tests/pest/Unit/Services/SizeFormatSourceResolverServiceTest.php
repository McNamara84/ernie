<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\SizeFormat\SizeFormatSourceResolverService;
use App\Support\SizeFormatFileRoleClassifier;

it('resolves ftp url and additional download links from the database without requiring a DOI', function (): void {
    $resource = Resource::factory()->create(['doi' => null]);
    $landingPage = LandingPage::factory()->for($resource)->draft()->withoutDoi()->create([
        'ftp_url' => 'https://datapub.gfz.de/download/dataset/',
        'primary_download_label' => 'Download data and description',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    LandingPageLink::query()->create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://datapub.gfz.de/download/extra.csv',
        'label' => 'Extra data',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 2,
    ]);
    LandingPageLink::query()->create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://example.org/project',
        'label' => 'Project',
        'kind' => LandingPageLink::KIND_RELATED,
        'position' => 1,
    ]);

    $sources = app(SizeFormatSourceResolverService::class)->resolve($resource);

    expect($sources)->toHaveCount(2)
        ->and(array_column($sources, 'kind'))->toBe(['ftp_url', 'additional_download_link'])
        ->and(array_column($sources, 'url'))->toBe([
            'https://datapub.gfz.de/download/dataset/',
            'https://datapub.gfz.de/download/extra.csv',
        ]);
});

it('deduplicates equivalent ftp and additional download URLs', function (): void {
    $resource = Resource::factory()->create();
    $landingPage = LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/dataset/',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    LandingPageLink::query()->create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://DATAPUB.GFZ.DE/download/dataset',
        'label' => 'Same data',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    expect(app(SizeFormatSourceResolverService::class)->resolve($resource))->toHaveCount(1);
});

it('does not resolve sources for external landing pages or unavailable downloads', function (bool $external): void {
    $resource = Resource::factory()->create();
    $factory = LandingPage::factory()->for($resource)->state([
        'ftp_url' => 'https://datapub.gfz.de/download/dataset/',
    ]);
    $landingPage = $external
        ? $factory->external()->create()
        : $factory->downloadsUnavailable()->create();

    expect(app(SizeFormatSourceResolverService::class)->resolve($landingPage->resource))->toBeEmpty();
})->with([true, false]);

it('classifies data-description variants by role without excluding arbitrary PDFs', function (): void {
    $classifier = app(SizeFormatFileRoleClassifier::class);

    expect($classifier->classify('sample_data-description.pdf')['role'])->toBe('data_description')
        ->and($classifier->classify('sample data description.pdf')['role'])->toBe('data_description')
        ->and($classifier->classify('folder%2Fsample_DataDescription.PDF')['role'])->toBe('data_description')
        ->and($classifier->classify('primary-observations.pdf')['role'])->toBe('primary_data')
        ->and($classifier->classify('metadata_description.pdf')['role'])->toBe('primary_data');
});
