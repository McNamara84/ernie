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
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('2 MB')
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

it('keeps base URL ports when resolving relative directory file links', function () {
    Http::fake([
        'https://datapub.gfz.de:8443/download/dataset/' => Http::response(<<<'HTML'
            <a href="data.csv">data.csv</a> 2026-06-14 10:00 1M
            HTML),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de:8443/download/dataset/');

    expect($result['raw_evidence']['files'][0]['file_url'])->toBe('https://datapub.gfz.de:8443/download/dataset/data.csv');
});

it('keeps Apache listing files with unknown size for format and confidence evidence', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="known.csv">known.csv</a> 2026-06-14 10:00 1M
            <a href="unknown.zip">unknown.zip</a> 2026-06-14 10:01 -
            HTML),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    expect($result['raw_evidence']['files'])->toHaveCount(2);

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($formatSuggestions)
        ->toHaveCount(2)
        ->and($formatSuggestions[1]['evidence']['filename'])->toBe('unknown.zip')
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['confidence'])->toBe('low')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(2);
});

it('infers high confidence size and format suggestions from HEAD headers', function () {
    Http::fake([
        'https://datapub.gfz.de/download/data.csv' => Http::response('', 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Length' => '1536',
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/data.csv');

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and($result['suggestions'])->toHaveCount(2)
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'text/csv',
            'probe_method' => 'CONTENT_TYPE_HEADER',
            'confidence' => 'high',
        ])
        ->and($result['suggestions'][1])->toMatchArray([
            'type' => 'size',
            'inferred_value' => '1.5 KB',
            'probe_method' => 'CONTENT_LENGTH_HEADER',
            'confidence' => 'high',
        ]);
});

it('falls back to ranged GET metadata when HEAD has no usable headers', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        return Http::response('PK', 206, [
            'Content-Type' => 'application/zip',
            'Content-Range' => 'bytes 0-1023/4096',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/archive.zip');

    expect($result['probe_method'])->toBe('RANGED_GET')
        ->and($result['suggestions'])->toHaveCount(2)
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'application/zip',
            'probe_method' => 'RANGED_GET_CONTENT_TYPE',
            'confidence' => 'medium',
        ])
        ->and($result['suggestions'][1])->toMatchArray([
            'type' => 'size',
            'inferred_value' => '4 KB',
            'probe_method' => 'RANGED_GET_CONTENT_RANGE',
            'confidence' => 'medium',
        ]);

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Range', ['bytes=0-1023']),
    );
});

it('ignores ranged GET responses when the server returns the full body', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        return Http::response('full file body', 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => '999999999',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/archive.zip');

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]['inferred_value'])->toBe('application/zip');

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Range', ['bytes=0-1023']),
    );
});

it('probes direct file links from landing pages with HEAD instead of full GET', function () {
    Http::fake([
        'https://dataservices.gfz-potsdam.de/landing' => Http::response(<<<'HTML'
            <html>
                <body>
                    <a class="piwik_download" href="/download/data.csv">Download data</a>
                </body>
            </html>
            HTML),
        'https://dataservices.gfz-potsdam.de/download/data.csv' => Http::response('', 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Length' => '2048',
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $results = $service->extractAndProbe('https://dataservices.gfz-potsdam.de/landing');

    expect($results)->toHaveCount(1)
        ->and($results[0]['probe_method'])->toBe('HTTP_HEAD')
        ->and($results[0]['suggestions'])->toHaveCount(2);

    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://dataservices.gfz-potsdam.de/download/data.csv',
    );
});

it('reuses the preflight HEAD response for extensionless direct downloads', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://dataservices.gfz-potsdam.de/landing') {
            return Http::response(<<<'HTML'
                <html>
                    <body>
                        <a class="piwik_download" href="/download/direct">Download data</a>
                    </body>
                </html>
                HTML);
        }

        return Http::response('', 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => '4096',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $results = $service->extractAndProbe('https://dataservices.gfz-potsdam.de/landing');

    expect($results)->toHaveCount(1)
        ->and($results[0]['probe_method'])->toBe('HTTP_HEAD')
        ->and($results[0]['suggestions'])->toHaveCount(2);

    Http::assertSentCount(2);
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === 'https://dataservices.gfz-potsdam.de/download/direct',
    );
    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://dataservices.gfz-potsdam.de/download/direct',
    );
});

it('does not probe absolute piwik download links on disallowed hosts', function () {
    Http::fake([
        'https://dataservices.gfz-potsdam.de/landing' => Http::response(<<<'HTML'
            <html>
                <body>
                    <a class="piwik_download" href="https://example.org/data.zip">Download data</a>
                </body>
            </html>
            HTML),
        'https://example.org/data.zip' => Http::response('', 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $results = $service->extractAndProbe('https://dataservices.gfz-potsdam.de/landing');

    expect($results)->toHaveCount(1)
        ->and($results[0]['probe_method'])->toBe('SKIP')
        ->and($results[0]['skip_reason'])->toBe('no_eligible_file_links_found');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'example.org'));
});

it('does not probe disallowed hosts through public direct probe methods', function () {
    Http::fake([
        'https://example.org/*' => Http::response('', 200),
    ]);

    $service = app(SizeFormatFileProbeService::class);

    $downloadResult = $service->probeDownloadUrl('https://example.org/data.zip');
    $directoryResult = $service->probeDirectoryListing('https://example.org/dataset/');
    $fileResult = $service->inferMetadataFromFileUrl('https://example.org/data.zip');

    expect($downloadResult['probe_method'])->toBe('SKIP')
        ->and($downloadResult['skip_reason'])->toBe('unsupported_source_url')
        ->and($directoryResult['probe_method'])->toBe('SKIP')
        ->and($directoryResult['skip_reason'])->toBe('unsupported_source_url')
        ->and($fileResult['probe_method'])->toBe('SKIP')
        ->and($fileResult['skip_reason'])->toBe('unsupported_source_url');

    Http::assertNothingSent();
});

it('falls back to compressed filename extensions when remote metadata is unavailable', function () {
    Http::fake([
        'https://datapub.gfz.de/download/export.csv.gz' => Http::response('', 404),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/export.csv.gz');

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'application/gzip',
            'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
            'confidence' => 'medium',
        ])
        ->and($result['suggestions'][0]['evidence']['extension'])->toBe('csv.gz');

    Http::assertSentCount(2);
});

it('builds low confidence aggregate size when only some directory file sizes parse', function () {
    $service = app(SizeFormatFileProbeService::class);

    $suggestions = $service->buildSuggestions([
        [
            'source_url' => 'https://datapub.gfz.de/download/dataset/',
            'probe_method' => 'DIRECTORY_LISTING',
            'raw_evidence' => [
                'files' => [
                    [
                        'file_url' => 'https://datapub.gfz.de/download/dataset/archive.tar.gz',
                        'filename' => 'archive.tar.gz',
                        'format' => 'tar.gz',
                        'file-size' => '1G',
                    ],
                    [
                        'file_url' => 'https://datapub.gfz.de/download/dataset/bundle.zip',
                        'filename' => 'bundle.zip',
                        'format' => 'zip',
                        'file-size' => '-',
                    ],
                ],
            ],
        ],
    ]);

    $formatSuggestions = array_values(array_filter(
        $suggestions,
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $suggestions,
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($formatSuggestions)->toHaveCount(2)
        ->and($formatSuggestions[0])->toMatchArray([
            'inferred_value' => 'application/gzip',
            'confidence' => 'medium',
        ])
        ->and($formatSuggestions[0]['evidence']['extension'])->toBe('tar.gz')
        ->and($formatSuggestions[1])->toMatchArray([
            'inferred_value' => 'application/zip',
            'confidence' => 'low',
        ])
        ->and($formatSuggestions[1]['evidence']['extension'])->toBe('zip')
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0])->toMatchArray([
            'inferred_value' => '1 GB',
            'confidence' => 'low',
        ])
        ->and(array_key_exists('files', $sizeSuggestions[0]['evidence']))->toBeFalse()
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(2);
});
