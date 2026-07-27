<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\LandingPageContentLinkService;

covers(LandingPageContentLinkService::class);

/** @return array{0: Resource, 1: LandingPage} */
function landingPageContentFixture(array $landingPageAttributes = []): array
{
    $resource = Resource::factory()->create();
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'ftp_url' => 'https://downloads.example.org/fallback.zip',
        ...$landingPageAttributes,
    ]);

    return [$resource, $landingPage];
}

test('uses the first valid stored format and imported files before the primary URL', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'not a mime type']);
    $resource->formats()->create(['value' => '.PDF']);
    $resource->formats()->create(['value' => 'application/zip']);

    $landingPage->files()->createMany([
        ['url' => 'https://downloads.example.org/second.pdf', 'position' => 1],
        ['url' => 'https://downloads.example.org/first.pdf', 'position' => 0],
    ]);
    $landingPage->links()->createMany([
        [
            'url' => 'https://downloads.example.org/extra.pdf',
            'label' => 'Extra file',
            'kind' => LandingPageLink::KIND_DOWNLOAD,
            'position' => 0,
        ],
        [
            'url' => 'https://git.example.org/project/repository',
            'label' => 'Repository',
            'kind' => LandingPageLink::KIND_REPOSITORY,
            'position' => 1,
        ],
    ]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['mimeType'])->toBe('application/pdf')
        ->and($result['contentLinks'])->toBe([
            ['url' => 'https://downloads.example.org/first.pdf', 'mimeType' => 'application/pdf'],
            ['url' => 'https://downloads.example.org/second.pdf', 'mimeType' => 'application/pdf'],
            ['url' => 'https://downloads.example.org/extra.pdf', 'mimeType' => 'application/pdf'],
        ])
        ->and($result['repositories'])->toBe(['https://git.example.org/project/repository'])
        ->and(array_column($result['contentLinks'], 'url'))->not->toContain('https://downloads.example.org/fallback.zip');
});

test('uses the original primary URL when no imported file exists', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'zip']);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['contentLinks'])->toBe([
        ['url' => 'https://downloads.example.org/fallback.zip', 'mimeType' => 'application/zip'],
    ]);
});

test('omits content without a valid database MIME type but retains repositories', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'unknown-format']);
    $landingPage->links()->create([
        'url' => 'https://git.example.org/project/repository',
        'label' => 'Repository',
        'kind' => LandingPageLink::KIND_REPOSITORY,
        'position' => 0,
    ]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['mimeType'])->toBeNull()
        ->and($result['contentLinks'])->toBe([])
        ->and($result['repositories'])->toBe(['https://git.example.org/project/repository']);
});

test('downloads unavailable suppresses every content URL', function () {
    [$resource, $landingPage] = landingPageContentFixture(['downloads_unavailable' => true]);
    $resource->formats()->create(['value' => 'application/zip']);
    $landingPage->files()->create(['url' => 'https://downloads.example.org/file.zip', 'position' => 0]);
    $landingPage->links()->create([
        'url' => 'https://downloads.example.org/extra.zip',
        'label' => 'Extra',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    expect(app(LandingPageContentLinkService::class)->resolve($resource, $landingPage)['contentLinks'])
        ->toBe([]);
});

test('rejects unsafe URLs and deduplicates safe URLs without inference', function () {
    [$resource, $landingPage] = landingPageContentFixture(['ftp_url' => 'javascript:alert(1)']);
    $resource->formats()->create(['value' => 'text/csv; charset=UTF-8']);
    $landingPage->files()->createMany([
        ['url' => 'https://downloads.example.org/data.csv', 'position' => 0],
        ['url' => 'https://downloads.example.org/data.csv', 'position' => 1],
        ['url' => "https://downloads.example.org/bad.csv\r\nX-Test: injected", 'position' => 2],
    ]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['mimeType'])->toBe('text/csv')
        ->and($result['contentLinks'])->toBe([
            ['url' => 'https://downloads.example.org/data.csv', 'mimeType' => 'text/csv'],
        ]);
});
