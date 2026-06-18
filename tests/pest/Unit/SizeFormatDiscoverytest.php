<?php

use App\Services\SizeFormatFileProbeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->probeService = new SizeFormatFileProbeService;
});

// 1. Professor Request: Test inaccessible URLs when the server returns a 500 error
it('handles inaccessible urls gracefully when server returns a 500 error', function () {
    $url = 'https://dataservices.gfz-potsdam.de/broken-link';

    Http::fake([
        $url => Http::response('Internal Server Error', 500),
    ]);

    $results = $this->probeService->extractAndProbe($url);

    expect($results)->toBeArray();
    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('landing_page_unreachable');
});

// 2. Professor Request: Test inaccessible URLs when connection times out completely
it('handles inaccessible urls when connection times out completely', function (): void {
    $url = 'https://dataservices.gfz-potsdam.de/timeout-link';

    Http::fake([
        $url => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $results = $this->probeService->extractAndProbe($url);

    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('exception');
});

// 3. Coverage Optimization: Skip unsupported protocols instantly
it('skips unsupported protocols instantly', function () {
    $url = 'ftp://dataservices.gfz-potsdam.de/file.zip';

    $results = $this->probeService->extractAndProbe($url);

    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('unsupported_protocol');
});

// 4. Coverage Optimization: Infers metadata from file headers via HTTP HEAD request
it('infers metadata from file headers via http head request', function (): void {
    $fileUrl = 'https://dataservices.gfz-potsdam.de/download/report.pdf';

    Http::fake([
        $fileUrl => Http::response('', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '1048576',
        ]),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl($fileUrl);

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and($result['suggestions'])->toHaveCount(2)
        ->and($result['suggestions'][0]['type'])->toBe('format')
        ->and($result['suggestions'][0]['inferred_value'])->toBe('application/pdf')
        ->and($result['suggestions'][0]['confidence'])->toBe('high')
        ->and($result['suggestions'][1]['type'])->toBe('size')
        ->and($result['suggestions'][1]['inferred_value'])->toBe('1 MB')
        ->and($result['suggestions'][1]['confidence'])->toBe('high');
});

it('uses ranged get when head has no usable metadata', function (): void {
    Http::fake([
        'https://files.example.org/data.bin' => Http::sequence()
            ->push('', 200, [
                'Content-Type' => '',
                'Content-Length' => '',
            ])

            ->push('', 206, [
                'Content-Type' => 'application/octet-stream',
                'Content-Range' => 'bytes 0-1023/4096',
            ]),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl('https://files.example.org/data.bin');

    expect($result['probe_method'])->toBe('RANGED_GET')
        ->and($result['suggestions'])->toHaveCount(2)
        ->and($result['suggestions'][0]['type'])->toBe('format')
        ->and($result['suggestions'][0]['inferred_value'])->toBe('application/octet-stream')
        ->and($result['suggestions'][1]['type'])->toBe('size')
        ->and($result['suggestions'][1]['inferred_value'])->toBe('4 KB');
});

it('falls back to filename extension when head and ranged get fail', function (): void {
    Http::fake([
        'https://files.example.org/data.zip' => Http::sequence()
            ->push('', 404)
            ->push('', 404),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl('https://files.example.org/data.zip');
    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['suggestions'][0]['type'])->toBe('format')
        ->and($result['suggestions'][0]['inferred_value'])->toBe('zip')
        ->and($result['suggestions'][0]['confidence'])->toBe('low');
});

it('detects composite filename extensions', function (): void {
    Http::fake([
        'https://files.example.org/orbit/file.sp3.gz' => Http::sequence()
            ->push('', 404)
            ->push('', 404),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl('https://files.example.org/orbit/file.sp3.gz');

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['suggestions'][0]['inferred_value'])->toBe('sp3.gz')
        ->and($result['suggestions'][0]['confidence'])->toBe('medium');
});

it('skips unsupported source urls after doi resolution', function (): void {
    Http::fake([
        'https://doi.org/10.1234/test' => Http::response('', 200),
    ]);

    $result = $this->probeService->extractAndProbe('https://doi.org/10.1234/test');
    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('unsupported_source_url');
});

it('skips blocked or form protected landing pages', function (): void {
    Http::fake([
        'https://dataservices.gfz-potsdam.de/protected' => Http::response(
            '<html><body><label>Purpose of use</label></body></html>',
            200,
        ),
    ]);

    $result = $this->probeService->extractAndProbe('https://dataservices.gfz-potsdam.de/protected');

    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('blocked_access_or_form_required');
});

it('skips landing pages without eligible download links', function (): void {
    Http::fake([
        'https://dataservices.gfz-potsdam.de/no-downloads' => Http::response(
            '<html><body><a href="/download/files/">Other link</a></body></html>',
            200,
        ),
    ]);

    $result = $this->probeService->extractAndProbe('https://dataservices.gfz-potsdam.de/no-downloads');
    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('SKIP')
        ->and($result[0]['skip_reason'])->toBe('no_eligible_file_links_found');
});

it('discovers allowed piwik download links from landing pages', function (): void {
    Http::fake([
        'https://dataservices.gfz-potsdam.de/landing-page' => Http::response(<<<'HTML'
            <html>
                <body>
                    <a class="piwik_download" href="/download/dataset/">Download data</a>
                </body>
            </html>
            HTML),
        'https://dataservices.gfz-potsdam.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="data.pdf">data.pdf</a> 2026-06-14 10:00 8M
            HTML),
    ]);

    $result = $this->probeService->extractAndProbe('https://dataservices.gfz-potsdam.de/landing-page');
    expect($result)->toHaveCount(1)
        ->and($result[0]['probe_method'])->toBe('DIRECTORY_LISTING')
        ->and($result[0]['suggestions'])->not->toBeEmpty();
});

it('deduplicates repeated files from directory listings', function (): void {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="one.pdf">one.pdf</a> 2026-06-14 10:00 1M
            <a href="two.pdf">two.pdf</a> 2026-06-14 10:01 1M
            <a href="one.pdf">one.pdf</a> 2026-06-14 10:00 1M
            HTML),
    ]);

    $result = $this->probeService->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');
    expect($result['raw_evidence']['files'])->toHaveCount(2);
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size'
    ));
    expect($sizeSuggestions)->toHaveCount(1);
});

it('explores nested directories and creates one total size suggestion', function (): void {
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

    $result = $this->probeService->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');
    expect($result['raw_evidence']['files'])->toHaveCount(3);
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($sizeSuggestions)
        ->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('2MB')
        ->and($sizeSuggestions[0]['confidence'])->toBe('high')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(3);
    Http::assertSentCount(3);
});

it('does not explore directories outside the original download tree', function (): void {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="file.csv">file.csv</a> 2026-06-14 10:00 1M
            <a href="https://example.org/external/">external</a>
            <a href="/download/other/">sibling</a>
            HTML),
    ]);

    $result = $this->probeService->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');
    expect($result['raw_evidence']['files'])->toHaveCount(1);
    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'example.org')
            || str_contains($request->url(), '/download/other/'),
    );
});
