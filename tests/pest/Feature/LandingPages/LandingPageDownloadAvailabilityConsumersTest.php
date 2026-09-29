<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\LandingPageContentLinkService;
use App\Services\LandingPageDownloadAvailabilityService;
use App\Services\MetadataAccessContentBackfillService;
use App\Services\SizeFormat\SizeFormatSourceResolverService;

covers(
    LandingPageDownloadAvailabilityService::class,
    LandingPageContentLinkService::class,
    MetadataAccessContentBackfillService::class,
    SizeFormatSourceResolverService::class,
);

test('download consumers agree on effective source availability without rewriting legacy state', function (
    ?string $primaryUrl,
    array $fileUrls,
    bool $suppressed,
    bool $additionalDownload,
    array $expectedUrls,
): void {
    $resource = Resource::factory()->create(['access_level' => null]);
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $landingPage = LandingPage::factory()->for($resource)->create([
        'ftp_url' => $primaryUrl,
        'downloads_unavailable' => false,
    ]);
    foreach ($fileUrls as $position => $url) {
        $landingPage->files()->create(['url' => $url, 'position' => $position]);
    }
    if ($additionalDownload) {
        $landingPage->links()->create([
            'url' => 'https://example.org/extra.zip',
            'label' => 'Additional download',
            'kind' => LandingPageLink::KIND_DOWNLOAD,
            'position' => 0,
        ]);
    }
    // Set after inserting files to represent historical suppression, before observer normalization.
    $landingPage->update(['downloads_unavailable' => $suppressed]);
    $landingPage->refresh();
    $available = $expectedUrls !== [];

    expect(app(LandingPageDownloadAvailabilityService::class)->isAvailable($landingPage))->toBe($available)
        ->and(array_column(app(LandingPageContentLinkService::class)->resolve($resource, $landingPage)['contentLinks'], 'url'))
        ->toBe($expectedUrls)
        ->and(array_column(app(SizeFormatSourceResolverService::class)->resolve($resource), 'url'))
        ->toBe($expectedUrls);

    $backfill = app(MetadataAccessContentBackfillService::class);
    $dryRun = $backfill->run();
    expect($dryRun['access_changes'])->toBe(1)
        ->and($resource->fresh()->access_level)->toBeNull()
        ->and($landingPage->fresh()->ftp_format_id)->toBeNull();

    $backfill->run(apply: true);
    expect($resource->fresh()->access_level)->toBe($available ? AccessLevel::OPEN : AccessLevel::METADATA_ONLY)
        ->and($landingPage->fresh()->downloads_unavailable)->toBe($suppressed)
        ->and($landingPage->fresh()->ftp_url)->toBe($primaryUrl);

    $assignedUrls = [];
    $landingPage->refresh();
    if ($landingPage->ftp_format_id === $format->id) {
        $assignedUrls[] = trim((string) $landingPage->ftp_url);
    }
    foreach ($landingPage->files()->orderBy('position')->get() as $file) {
        if ($file->format_id === $format->id) {
            $assignedUrls[] = trim($file->url);
        }
    }
    foreach ($landingPage->links()->orderBy('position')->get() as $link) {
        if ($link->format_id === $format->id) {
            $assignedUrls[] = trim($link->url);
        }
    }
    expect($assignedUrls)->toBe($expectedUrls)
        ->and($backfill->run(apply: true)['access_changes'])->toBe(0)
        ->and($backfill->run(apply: true)['format_changes'])->toBe(0);
})->with([
    'fresh empty page' => [null, [], false, false, []],
    'empty historical suppression' => [null, [], true, false, []],
    'whitespace and placeholder files' => ['  ', ['#', ' '], false, false, []],
    'invalid primary URL' => ['javascript:alert(1)', [], false, false, []],
    'additional download alone' => [null, [], false, true, []],
    'placeholder files and additional download' => [null, ['#'], false, true, []],
    'primary download' => ['https://example.org/main.zip', [], false, false, ['https://example.org/main.zip']],
    'trimmed primary download' => [' https://example.org/main.zip ', [], false, false, ['https://example.org/main.zip']],
    'placeholder files allow primary fallback' => ['https://example.org/main.zip', ['#', 'invalid'], false, false, ['https://example.org/main.zip']],
    'imported download without primary' => [null, ['https://example.org/file.zip'], false, false, ['https://example.org/file.zip']],
    'usable files take precedence over primary' => ['https://example.org/main.zip', ['#', 'https://example.org/file.zip'], false, false, ['https://example.org/file.zip']],
    'primary with additional download' => ['https://example.org/main.zip', [], false, true, ['https://example.org/main.zip', 'https://example.org/extra.zip']],
    'file with additional download' => [null, ['https://example.org/file.zip'], false, true, ['https://example.org/file.zip', 'https://example.org/extra.zip']],
    'suppressed primary and additional download' => ['https://example.org/main.zip', [], true, true, []],
    'suppressed imported file' => [null, ['https://example.org/file.zip'], true, false, []],
    'suppressed additional download alone' => [null, [], true, true, []],
]);

test('access backfill preserves an explicitly assigned access level on request-only pages', function (AccessLevel $accessLevel): void {
    $resource = Resource::factory()->create(['access_level' => $accessLevel]);
    LandingPage::factory()->for($resource)->create(['ftp_url' => null, 'downloads_unavailable' => false]);

    expect(app(MetadataAccessContentBackfillService::class)->run(apply: true)['access_changes'])->toBe(0)
        ->and($resource->fresh()->access_level)->toBe($accessLevel);
})->with(AccessLevel::cases());

test('access backfill preserves the existing fallback for resources without an internal landing page', function (bool $external): void {
    $resource = Resource::factory()->create(['access_level' => null]);
    if ($external) {
        LandingPage::factory()->for($resource)->external()->create();
    }

    app(MetadataAccessContentBackfillService::class)->run(apply: true);

    expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and(app(SizeFormatSourceResolverService::class)->resolve($resource->fresh()))->toBe([]);
})->with([true, false]);

test('invalid additional URLs do not become content backfill or discovery targets', function (): void {
    $resource = Resource::factory()->create(['access_level' => null]);
    $resource->formats()->create(['value' => 'application/zip']);
    $size = $resource->sizes()->create(['numeric_value' => 2, 'unit' => 'MB']);
    $landingPage = LandingPage::factory()->for($resource)->create(['ftp_url' => 'https://example.org/main.zip']);
    $placeholder = $landingPage->files()->create(['url' => '#', 'position' => 0]);
    $link = $landingPage->links()->create([
        'url' => 'javascript:alert(1)',
        'label' => 'Invalid legacy link',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    app(MetadataAccessContentBackfillService::class)->run(apply: true);

    expect($landingPage->fresh()->ftp_size_id)->toBe($size->id)
        ->and($placeholder->fresh()->format_id)->toBeNull()
        ->and($link->fresh()->format_id)->toBeNull()
        ->and($link->fresh()->size_id)->toBeNull()
        ->and(array_column(app(SizeFormatSourceResolverService::class)->resolve($resource), 'url'))
        ->toBe(['https://example.org/main.zip']);
});
