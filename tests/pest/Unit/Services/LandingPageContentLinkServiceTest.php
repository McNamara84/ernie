<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceType;
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

test('uses the descriptors assigned to each imported file and download link', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'not a mime type']);
    $pdf = $resource->formats()->create(['value' => '.PDF']);
    $zip = $resource->formats()->create(['value' => 'application/zip']);
    $size = $resource->sizes()->create(['numeric_value' => 1.5, 'unit' => 'MB']);

    $landingPage->files()->createMany([
        ['url' => 'https://downloads.example.org/second.pdf', 'position' => 1, 'format_id' => $pdf->id],
        ['url' => 'https://downloads.example.org/first.pdf', 'position' => 0, 'format_id' => $pdf->id, 'size_id' => $size->id],
    ]);
    $landingPage->links()->createMany([
        [
            'url' => 'https://downloads.example.org/extra.pdf',
            'label' => 'Extra file',
            'kind' => LandingPageLink::KIND_DOWNLOAD,
            'position' => 0,
            'format_id' => $zip->id,
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
            ['url' => 'https://downloads.example.org/first.pdf', 'mimeType' => 'application/pdf', 'contentSize' => '1500000'],
            ['url' => 'https://downloads.example.org/second.pdf', 'mimeType' => 'application/pdf', 'contentSize' => null],
            ['url' => 'https://downloads.example.org/extra.pdf', 'mimeType' => 'application/zip', 'contentSize' => null],
        ])
        ->and($result['repositories'])->toBe(['https://git.example.org/project/repository'])
        ->and(array_column($result['contentLinks'], 'url'))->not->toContain('https://downloads.example.org/fallback.zip');
});

test('uses the original primary URL when no imported file exists', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $format = $resource->formats()->create(['value' => 'zip']);
    $landingPage->update(['ftp_format_id' => $format->id]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['contentLinks'])->toBe([
        ['url' => 'https://downloads.example.org/fallback.zip', 'mimeType' => 'application/zip', 'contentSize' => null],
    ]);
});

test('falls back to the only valid resource MIME type for the primary URL', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'unknown-format']);
    $resource->formats()->create(['value' => '.ZIP']);
    $size = $resource->sizes()->create(['numeric_value' => 2, 'unit' => 'MB']);
    $landingPage->update(['ftp_size_id' => $size->id]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['mimeType'])->toBe('application/zip')
        ->and($result['contentLinks'])->toBe([[
            'url' => 'https://downloads.example.org/fallback.zip',
            'mimeType' => 'application/zip',
            'contentSize' => '2000000',
        ]]);
});

test('uses the unambiguous MIME fallback for imported files and download links', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'application/pdf']);
    $landingPage->files()->create([
        'url' => 'https://downloads.example.org/file.pdf',
        'position' => 0,
    ]);
    $landingPage->links()->create([
        'url' => 'https://downloads.example.org/extra.pdf',
        'label' => 'Extra',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    expect(app(LandingPageContentLinkService::class)->resolve($resource, $landingPage)['contentLinks'])
        ->toBe([
            ['url' => 'https://downloads.example.org/file.pdf', 'mimeType' => 'application/pdf', 'contentSize' => null],
            ['url' => 'https://downloads.example.org/extra.pdf', 'mimeType' => 'application/pdf', 'contentSize' => null],
        ]);
});

test('does not infer a MIME type when multiple valid resource formats exist', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'application/pdf']);
    $resource->formats()->create(['value' => 'application/zip']);

    expect(app(LandingPageContentLinkService::class)->resolve($resource, $landingPage)['contentLinks'])
        ->toBe([]);
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
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $landingPage->files()->create(['url' => 'https://downloads.example.org/file.zip', 'position' => 0, 'format_id' => $format->id]);
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
    $format = $resource->formats()->create(['value' => 'text/csv; charset=UTF-8']);
    $landingPage->files()->createMany([
        ['url' => 'https://downloads.example.org/data.csv', 'position' => 0, 'format_id' => $format->id],
        ['url' => 'https://downloads.example.org/data.csv', 'position' => 1, 'format_id' => $format->id],
        ['url' => "https://downloads.example.org/bad.csv\r\nX-Test: injected", 'position' => 2],
    ]);

    $result = app(LandingPageContentLinkService::class)->resolve($resource, $landingPage);

    expect($result['mimeType'])->toBe('text/csv')
        ->and($result['contentLinks'])->toBe([
            ['url' => 'https://downloads.example.org/data.csv', 'mimeType' => 'text/csv', 'contentSize' => null],
        ]);
});

test('does not use descriptors that belong to a different resource', function () {
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->formats()->create(['value' => 'text/csv']);
    $other = Resource::factory()->create();
    $foreignFormat = $other->formats()->create(['value' => 'application/zip']);
    $foreignSize = $other->sizes()->create(['numeric_value' => 2, 'unit' => 'MB']);

    $landingPage->forceFill([
        'ftp_format_id' => $foreignFormat->id,
        'ftp_size_id' => $foreignSize->id,
    ])->save();

    expect(app(LandingPageContentLinkService::class)->resolve($resource, $landingPage)['contentLinks'])
        ->toBe([]);
});

test('never projects an IGSN physical size as digital content size', function () {
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    [$resource, $landingPage] = landingPageContentFixture();
    $resource->update(['resource_type_id' => $physicalObject->id]);
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $physicalSize = $resource->sizes()->create(['numeric_value' => 42, 'unit' => 'mm']);
    $landingPage->update([
        'ftp_format_id' => $format->id,
        'ftp_size_id' => $physicalSize->id,
    ]);

    expect(app(LandingPageContentLinkService::class)->resolve($resource->fresh(), $landingPage->fresh())['contentLinks'])
        ->toBe([[
            'url' => 'https://downloads.example.org/fallback.zip',
            'mimeType' => 'application/zip',
            'contentSize' => null,
        ]]);
});
