<?php

declare(strict_types=1);

use App\Services\SizeFormatFileProbeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(SizeFormatFileProbeService::class);

it('explores nested directories and creates one total size suggestion', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="root.csv">root.csv</a> 2026-06-14 10:00 1M
            <a href="nested/">nested/</a>
            HTML),
        'https://datapub.gfz.de/download/dataset/nested/' => Http::response(<<<'HTML'
            <a href="child.zip">child.zip</a> 2026-06-14 10:01 512K
            <a href="deeper/">deeper/</a>
            <a href="../">Parent Directory</a>
            HTML),
        'https://datapub.gfz.de/download/dataset/nested/deeper/' => Http::response(<<<'HTML'
            <a href="data.json">data.json</a> 2026-06-14 10:02 0.5M
            HTML),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    expect($result['raw_evidence']['files'])->toHaveCount(3);

    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($sizeSuggestions)
        ->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('2M')
        ->and($sizeSuggestions[0]['confidence'])->toBe('high')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(3);

    Http::assertSentCount(3);
});

it('does not explore directories outside the original download tree', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="file.csv">file.csv</a> 2026-06-14 10:00 1M
            <a href="https://example.org/external/">external</a>
            <a href="/download/other/">sibling</a>
            HTML),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    expect($result['raw_evidence']['files'])->toHaveCount(1);

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'example.org')
            || str_contains($request->url(), '/download/other/'),
    );
});
