<?php

declare(strict_types=1);

use App\Services\SizeFormatFileProbeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Tests\Helpers\sizeFormatZipFixtureData;

covers(SizeFormatFileProbeService::class);

function sizeFormatProbeStepanovZipFiles(): array
{
    $base = '2026-067_Stepanov-et-al_data';
    $csvBase = $base.'/2026-067_Stepanov-et-al_data_csv';

    return [
        $base.'/2026-067_Stepanov-et-al_1_Re-Os-isotope-data-ingot.xlsx' => str_repeat('x', 24000),
        $base.'/2026-067_Stepanov-et-al_2_Re-Os-isotope-data-powder.xlsx' => str_repeat('x', 22000),
        $base.'/2026-067_Stepanov-et-al_3_Re-Os-isotope-data-reference-material.xlsx' => str_repeat('x', 21000),
        $base.'/2026-067_Stepanov-et-al_4_Major-elements.xlsx' => str_repeat('x', 20500),
        $base.'/2026-067_Stepanov-et-al_5_Trace-elements.xlsx' => str_repeat('x', 19500),
        $base.'/2026-067_Stepanov-et-al_6_Rhenium-Osmium.xlsx' => str_repeat('x', 18000),
        $base.'/2026-067_Stepanov-et-al_7_Sample-list.xlsx' => str_repeat('x', 17000),
        $base.'/2026-067_Stepanov-et-al_8_Metadata.xlsx' => str_repeat('x', 16000),
        $base.'/2026-067_Stepanov-et-al_9_Readme.xlsx' => str_repeat('x', 15000),
        $csvBase.'/2026-067_Stepanov-et-al_1_Re-Os-isotope-data-ingot.csv' => str_repeat('c', 30000),
        $csvBase.'/2026-067_Stepanov-et-al_2_Re-Os-isotope-data-powder.csv' => str_repeat('c', 29000),
        $csvBase.'/2026-067_Stepanov-et-al_3_Re-Os-isotope-data-reference-material.csv' => str_repeat('c', 28500),
        $csvBase.'/2026-067_Stepanov-et-al_4_Major-elements.csv' => str_repeat('c', 28000),
        $csvBase.'/2026-067_Stepanov-et-al_5_Trace-elements.csv' => str_repeat('c', 27500),
        $csvBase.'/2026-067_Stepanov-et-al_6_Rhenium-Osmium.csv' => str_repeat('c', 27055),
    ];
}

it('explores nested directories and creates one total size suggestion', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="root.csv">root.csv</a> 2026-06-14 10:00 1M
            <a href="nested/">nested/</a>
            HTML),
        'https://datapub.gfz.de/download/dataset/nested/' => Http::response(<<<'HTML'
            <a href="child.txt">child.txt</a> 2026-06-14 10:01 512K
            <a href="deeper/">deeper/</a>
            <a href="../">Parent Directory</a>
            HTML),
        'https://datapub.gfz.de/download/dataset/nested/deeper/' => Http::response(<<<'HTML'
            <a href="data.json">data.json</a> 2026-06-14 10:02 0.5M
            HTML),
        'https://datapub.gfz.de/download/dataset/root.csv' => Http::response('', 200, ['Content-Length' => '1048576']),
        'https://datapub.gfz.de/download/dataset/nested/child.txt' => Http::response('', 200, ['Content-Length' => '524288']),
        'https://datapub.gfz.de/download/dataset/nested/deeper/data.json' => Http::response('', 200, ['Content-Length' => '524288']),
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
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('2097152 Primary Data Size [bytes]')
        ->and($sizeSuggestions[0]['confidence'])->toBe('high')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(3);

    Http::assertSentCount(6);
});

it('excludes data description files from directory format and size suggestions', function () {
    Http::fake([
        'https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/' => Http::response(<<<'HTML'
            <a href="2026-047_Moreira-et-al_data/">2026-047_Moreira-et-al_data/</a> 2026-07-03 14:38 -
            <a href="2026-047_Moreira-et-al_data-description.pdf">2026-047_Moreira-et-al_data-description.pdf</a> 2026-07-03 14:38 450K
            HTML),
        'https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/2026-047_Moreira-et-al_data/' => Http::response(<<<'HTML'
            <a href="2026-047_Moreira-et-al_data-Lisbon1.csv">2026-047_Moreira-et-al_data-Lisbon1.csv</a> 2026-07-03 14:38 41K
            <a href="2026-047_Moreira-et-al_data-Lisbon2.csv">2026-047_Moreira-et-al_data-Lisbon2.csv</a> 2026-07-03 14:38 53K
            HTML),
        'https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/2026-047_Moreira-et-al_data/2026-047_Moreira-et-al_data-Lisbon1.csv' => Http::response('', 200, ['Content-Length' => '41984']),
        'https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/2026-047_Moreira-et-al_data/2026-047_Moreira-et-al_data-Lisbon2.csv' => Http::response('', 200, ['Content-Length' => '54272']),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/');

    expect($result['raw_evidence']['files'])->toHaveCount(3)
        ->and(array_column($result['raw_evidence']['files'], 'filename'))->toEqualCanonicalizing([
            '2026-047_Moreira-et-al_data-Lisbon1.csv',
            '2026-047_Moreira-et-al_data-Lisbon2.csv',
            '2026-047_Moreira-et-al_data-description.pdf',
        ]);

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect(array_column($formatSuggestions, 'inferred_value'))
        ->each->toBe('text/csv')
        ->and(array_column($formatSuggestions, 'source_url'))->not->toContain('https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/2026-047_Moreira-et-al_data-description.pdf')
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('96256 Primary Data Size [bytes]')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(2)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(2)
        ->and($sizeSuggestions[0]['evidence']['excluded_files'][0]['role'])->toBe('data_description');

    Http::assertSentCount(4);
});

it('skips direct data description file probes before sending http requests', function () {
    Http::fake();

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/10.5880.FIDGEO.2026.047-Mnbvfgh/2026-047_Moreira-et-al_data-description.pdf');

    expect($result['probe_method'])->toBe('SKIP')
        ->and($result['skip_reason'])->toBe('data_description_file')
        ->and($result['suggestions'])->toBeEmpty();

    Http::assertNothingSent();
});

it('allows configured download sources on public hosts outside the GFZ domains', function () {
    $url = 'https://93.184.216.34/data.csv';

    Http::fake([
        $url => Http::response('', 200, [
            'Content-Type' => 'text/csv',
            'Content-Length' => '2665858',
        ]),
    ]);

    $result = app(SizeFormatFileProbeService::class)->inferMetadataFromFileUrl($url);

    expect($result['suggestions'])
        ->toHaveCount(2)
        ->and(array_column($result['suggestions'], 'inferred_value'))
        ->toContain('text/csv', '2665858 Primary Data Size [bytes]');
});

it('rejects private download targets through every public probe method', function () {
    Http::fake();

    $service = app(SizeFormatFileProbeService::class);
    $downloadResult = $service->probeDownloadUrl('http://127.0.0.1/private.csv');
    $directoryResult = $service->probeDirectoryListing('http://127.0.0.1/private/');
    $fileResult = $service->inferMetadataFromFileUrl('http://127.0.0.1/private.csv');

    expect($downloadResult['probe_method'])->toBe('SKIP')
        ->and($downloadResult['skip_reason'])->toBe('unsupported_source_url')
        ->and($directoryResult['probe_method'])->toBe('SKIP')
        ->and($directoryResult['skip_reason'])->toBe('unsupported_source_url')
        ->and($fileResult['probe_method'])->toBe('SKIP')
        ->and($fileResult['skip_reason'])->toBe('unsupported_source_url');

    Http::assertNothingSent();
});

it('discards unsafe file links from untrusted directory listings', function () {
    $directoryUrl = 'https://93.184.216.34/download/dataset/';
    $safeFileUrl = $directoryUrl.'safe.csv';

    Http::fake([
        $directoryUrl => Http::response(<<<'HTML'
            <a href="safe.csv">safe.csv</a> 2026-06-14 10:00 1K
            <a href="javascript:alert(1)">script.csv</a> 2026-06-14 10:01 1K
            <a href="http://127.0.0.1/private.csv">private.csv</a> 2026-06-14 10:02 1K
            HTML),
        $safeFileUrl => Http::response('', 200, ['Content-Length' => '1024']),
    ]);

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);

    expect($result['raw_evidence']['files'])->toHaveCount(1)
        ->and($result['raw_evidence']['files'][0]['file_url'])->toBe($safeFileUrl)
        ->and(array_column($result['suggestions'], 'source_url'))->not->toContain(
            'javascript:alert(1)',
            'http://127.0.0.1/private.csv',
        );

    Http::assertSentCount(2);
    Http::assertNotSent(
        fn (Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1'),
    );
});

it('validates every redirect target before following it', function () {
    $url = 'https://datapub.gfz.de/download/data.csv';

    Http::fake([
        $url => Http::response('', 302, ['Location' => 'http://127.0.0.1/private.csv']),
    ]);

    $result = app(SizeFormatFileProbeService::class)->inferMetadataFromFileUrl($url);

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['probe_complete'])->toBeFalse()
        ->and($result['raw_evidence']['error'])->toBe('unsafe_download_url');

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1'),
    );
});

it('resolves query-only redirect locations against the complete file URL', function () {
    $url = 'https://93.184.216.34/download/data.csv';
    $redirectedUrl = $url.'?token=signed';

    Http::fake(function (Request $request) use ($url, $redirectedUrl) {
        if ($request->url() === $url) {
            return Http::response('', 302, ['Location' => '?token=signed']);
        }

        if ($request->url() === $redirectedUrl) {
            return Http::response('', 200, [
                'Content-Type' => 'text/csv',
                'Content-Length' => '321',
            ]);
        }

        return Http::response('', 404);
    });

    $result = app(SizeFormatFileProbeService::class)->inferMetadataFromFileUrl($url);

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and(array_column($result['suggestions'], 'inferred_value'))
        ->toContain('321 Primary Data Size [bytes]');

    Http::assertSent(fn (Request $request): bool => $request->url() === $redirectedUrl);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/?token=signed');
});

it('preserves signed directory queries for recursive listings and file probes', function () {
    $directoryUrl = 'https://93.184.216.34/download/dataset/?token=signed';
    $childUrl = 'https://93.184.216.34/download/dataset/child/?token=signed';
    $fileUrl = 'https://93.184.216.34/download/dataset/child/data.csv?token=signed';

    Http::fake([
        $directoryUrl => Http::response('<a href="child/">child/</a>'),
        $childUrl => Http::response(<<<'HTML'
            <a href="data.csv">data.csv</a> 2026-06-14 10:00 1K
            HTML),
        $fileUrl => Http::response('', 200, ['Content-Length' => '1024']),
    ]);

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);

    expect($result['probe_complete'])->toBeTrue()
        ->and($result['source_url'])->toBe($directoryUrl)
        ->and($result['raw_evidence']['files'][0]['file_url'])->toBe($fileUrl)
        ->and(array_column($result['suggestions'], 'inferred_value'))
        ->toContain('1024 Primary Data Size [bytes]');

    Http::assertSent(fn (Request $request): bool => $request->url() === $childUrl);
    Http::assertSent(fn (Request $request): bool => $request->url() === $fileUrl);
});

it('recognizes data descriptions behind encoded path separators', function () {
    $url = 'https://datapub.gfz.de/download/dataset/archive%2Fdata-description.pdf';

    Http::fake([
        $url => Http::response('', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '2048',
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl($url);

    expect($result['probe_method'])->toBe('SKIP')
        ->and($result['skip_reason'])->toBe('data_description_file')
        ->and($result['excluded_role'])->toBe('data_description');

    Http::assertNothingSent();
});

it('applies data description filename matching narrowly and case insensitively', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="sample_data-description.pdf">sample_data-description.pdf</a> 2026-06-14 10:00 1K
            <a href="sample_data_description.pdf">sample_data_description.pdf</a> 2026-06-14 10:01 2K
            <a href="sample_DataDescription.PDF">sample_DataDescription.PDF</a> 2026-06-14 10:02 3K
            <a href="sample_description.pdf">sample_description.pdf</a> 2026-06-14 10:03 4K
            <a href="metadata_description.pdf">metadata_description.pdf</a> 2026-06-14 10:04 7K
            <a href="readme.pdf">readme.pdf</a> 2026-06-14 10:05 5K
            <a href="data.csv">data.csv</a> 2026-06-14 10:06 6K
            HTML),
        'https://datapub.gfz.de/download/dataset/sample_description.pdf' => Http::response('', 200, ['Content-Length' => '4096']),
        'https://datapub.gfz.de/download/dataset/metadata_description.pdf' => Http::response('', 200, ['Content-Length' => '7168']),
        'https://datapub.gfz.de/download/dataset/readme.pdf' => Http::response('', 200, ['Content-Length' => '5120']),
        'https://datapub.gfz.de/download/dataset/data.csv' => Http::response('', 200, ['Content-Length' => '6144']),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');
    $filenames = array_column($result['raw_evidence']['files'], 'filename');

    expect($filenames)->toEqualCanonicalizing([
        'sample_data-description.pdf',
        'sample_data_description.pdf',
        'sample_DataDescription.PDF',
        'sample_description.pdf',
        'metadata_description.pdf',
        'readme.pdf',
        'data.csv',
    ]);

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect(array_column($formatSuggestions, 'inferred_value'))->toEqualCanonicalizing([
        'application/pdf',
        'application/pdf',
        'application/pdf',
        'text/csv',
    ])
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('22528 Primary Data Size [bytes]')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(4)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(4);
});

it('does not explore directories outside the original download tree', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="file.csv">file.csv</a> 2026-06-14 10:00 1M
            <a href="https://example.org/external/">external</a>
            <a href="/download/other/">sibling</a>
            HTML),
        'https://datapub.gfz.de/download/dataset/file.csv' => Http::response('', 200, ['Content-Length' => '1048576']),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    expect($result['raw_evidence']['files'])->toHaveCount(1);

    Http::assertSentCount(2);
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
            <a href="unknown.dat">unknown.dat</a> 2026-06-14 10:01 -
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
        ->and($formatSuggestions[1]['evidence']['filename'])->toBe('unknown.dat')
        ->and($sizeSuggestions)->toBeEmpty();
});

it('uses exact response metadata instead of rounded Apache display sizes', function () {
    $directoryUrl = 'https://datapub.gfz.de/download/dataset/';
    $fileUrl = $directoryUrl.'measurement.csv';

    Http::fake([
        $directoryUrl => Http::response(<<<'HTML'
            <a href="measurement.csv">measurement.csv</a> 2026-06-14 10:00 1.9M
            HTML),
        $fileUrl => Http::response('', 200, ['Content-Length' => '1945321']),
    ]);

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($result['probe_complete'])->toBeTrue()
        ->and($result['raw_evidence']['files'][0]['file-size'])->toBe('1.9M')
        ->and($result['raw_evidence']['files'][0]['exact_size_bytes'])->toBe(1945321)
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('1945321 Primary Data Size [bytes]');
});

it('uses an exact content range when a directory file HEAD response has no size', function () {
    $directoryUrl = 'https://datapub.gfz.de/download/dataset/';
    $fileUrl = $directoryUrl.'measurement.csv';

    Http::fake(function (Request $request) use ($directoryUrl, $fileUrl) {
        if ($request->url() === $directoryUrl) {
            return Http::response(<<<'HTML'
                <a href="measurement.csv">measurement.csv</a> 2026-06-14 10:00 1.9M
                HTML);
        }

        if ($request->url() === $fileUrl && $request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        return Http::response('', 206, ['Content-Range' => 'bytes 0-0/1945321']);
    });

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);

    expect($result['probe_complete'])->toBeTrue()
        ->and($result['raw_evidence']['files'][0]['exact_size_bytes'])->toBe(1945321)
        ->and($result['raw_evidence']['files'][0]['exact_size_probe_method'])->toBe('RANGED_GET_CONTENT_RANGE')
        ->and(array_column($result['suggestions'], 'inferred_value'))
        ->toContain('1945321 Primary Data Size [bytes]');

    Http::assertSent(
        fn (Request $request): bool => $request->url() === $fileUrl
            && $request->method() === 'GET'
            && $request->hasHeader('Range', ['bytes=0-0']),
    );
});

it('marks a directory probe incomplete when a child directory cannot be inspected', function () {
    $directoryUrl = 'https://datapub.gfz.de/download/dataset/';

    Http::fake([
        $directoryUrl => Http::response(<<<'HTML'
            <a href="root.csv">root.csv</a> 2026-06-14 10:00 1K
            <a href="child/">child/</a>
            HTML),
        $directoryUrl.'child/' => Http::response('', 500),
        $directoryUrl.'root.csv' => Http::response('', 200, ['Content-Length' => '1024']),
    ]);

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($result['probe_complete'])->toBeFalse()
        ->and($result['raw_evidence']['files'])->toHaveCount(1)
        ->and($sizeSuggestions)->toBeEmpty();
});

it('bounds exact file-size requests and marks the directory result incomplete', function () {
    $directoryUrl = 'https://93.184.216.34/download/dataset/';
    $requestLimit = (int) (new ReflectionClass(SizeFormatFileProbeService::class))
        ->getConstant('MAX_DIRECTORY_FILE_SIZE_REQUESTS');
    $rows = [];

    for ($index = 0; $index <= $requestLimit; $index++) {
        $filename = 'data-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.csv';
        $rows[] = sprintf('<a href="%s">%s</a> 2026-06-14 10:00 1K', $filename, $filename);
    }

    Http::fake(function (Request $request) use ($directoryUrl, $rows) {
        if ($request->url() === $directoryUrl) {
            return Http::response(implode("\n", $rows));
        }

        return Http::response('', 200, ['Content-Length' => '1024']);
    });

    $result = app(SizeFormatFileProbeService::class)->probeDirectoryListing($directoryUrl);
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($result['probe_complete'])->toBeFalse()
        ->and($result['raw_evidence']['file_size_probe'])->toMatchArray([
            'max_requests' => $requestLimit,
            'requests_used' => $requestLimit,
            'budget_exhausted' => true,
        ])
        ->and($sizeSuggestions)->toBeEmpty();

    Http::assertSentCount($requestLimit + 1);
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
            'inferred_value' => '1536 Primary Data Size [bytes]',
            'probe_method' => 'CONTENT_LENGTH_HEADER',
            'confidence' => 'high',
        ]);
});

it('reads direct ZIP contents for contained formats and uncompressed size', function () {
    $zipData = sizeFormatZipFixtureData([
        'data/table.csv' => str_repeat('c', 1024),
        'docs/manual.pdf' => str_repeat('p', 2048),
        'docs/data-description.pdf' => str_repeat('x', 4096),
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/archive.zip' => Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/archive.zip');

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($result['probe_method'])->toBe('ZIP_CONTENT_LISTING')
        ->and(array_column($formatSuggestions, 'inferred_value'))->toEqualCanonicalizing([
            'application/zip',
            'text/csv',
            'application/pdf',
        ])
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0])->toMatchArray([
            'inferred_value' => '3072 Uncompressed Primary Data Size [bytes]',
            'probe_method' => 'ZIP_CONTENT_LISTING',
            'confidence' => 'high',
        ])
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(2)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(2)
        ->and($sizeSuggestions[0]['evidence']['raw_entry_count'])->toBe(3)
        ->and($sizeSuggestions[0]['evidence']['skipped_entry_count'])->toBe(1);

    Http::assertSentCount(2);
});

it('counts ZIP directory entries as skipped evidence', function () {
    $zipData = sizeFormatZipFixtureData([
        'data/' => null,
        'data/table.csv' => 'csv',
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/archive-with-dir.zip' => Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/archive-with-dir.zip');

    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['evidence']['raw_entry_count'])->toBe(2)
        ->and($sizeSuggestions[0]['evidence']['skipped_entry_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(1);

    Http::assertSentCount(2);
});

it('uses ZIP contents from directory listings for formats and aggregate size', function () {
    $zipData = sizeFormatZipFixtureData([
        'inside/data.csv' => str_repeat('c', 2048),
        'inside/plot.pdf' => str_repeat('p', 3072),
        'inside/data-description.txt' => str_repeat('x', 1024),
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="readme.txt">readme.txt</a> 2026-06-14 10:00 1K
            <a href="archive.zip">archive.zip</a> 2026-06-14 10:01 4K
            HTML),
        'https://datapub.gfz.de/download/dataset/readme.txt' => Http::response('', 200, ['Content-Length' => '1024']),
        'https://datapub.gfz.de/download/dataset/archive.zip' => Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect(array_column($formatSuggestions, 'inferred_value'))->toEqualCanonicalizing([
        'text/plain',
        'application/zip',
        'text/csv',
        'application/pdf',
    ])
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('6144 Uncompressed Primary Data Size [bytes]')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(3)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(3)
        ->and($sizeSuggestions[0]['evidence']['zip_archive_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['zip_entry_count'])->toBe(2);

    Http::assertSentCount(3);
});

it('limits ZIP content inspections per directory listing', function () {
    $inspectionLimit = (int) (new ReflectionClass(SizeFormatFileProbeService::class))->getConstant('MAX_DIRECTORY_ZIP_INSPECTIONS');
    $links = [];

    for ($index = 0; $index <= $inspectionLimit; $index++) {
        $links[] = sprintf('<a href="archive-%02d.zip">archive-%02d.zip</a> 2026-06-14 10:%02d 1K', $index, $index, $index);
    }

    $zipData = sizeFormatZipFixtureData([
        'inside/data.csv' => 'csv',
    ]);

    Http::fake(function (Request $request) use ($links, $zipData) {
        if ($request->url() === 'https://datapub.gfz.de/download/dataset/') {
            return Http::response(implode("\n", $links));
        }

        return Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    $inspectedFiles = array_values(array_filter(
        $result['raw_evidence']['files'],
        fn (array $file): bool => array_key_exists('zip_probe_result', $file),
    ));

    expect($result['raw_evidence']['files'])->toHaveCount($inspectionLimit + 1)
        ->and($inspectedFiles)->toHaveCount($inspectionLimit)
        ->and($result['raw_evidence']['files'][$inspectionLimit])->not->toHaveKey('zip_probe_result');

    Http::assertSentCount(1 + $inspectionLimit);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), sprintf('archive-%02d.zip', $inspectionLimit)));
});

it('inspects ZIP links on public external hosts from configured directory listings', function () {
    Http::fake([
        'https://datapub.gfz.de/download/dataset/' => Http::response(<<<'HTML'
            <a href="https://example.org/archive.zip">archive.zip</a> 2026-06-14 10:01 4K
            HTML),
        'https://example.org/archive.zip' => Http::response(sizeFormatZipFixtureData([
            'inside/data.csv' => 'csv',
        ]), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing('https://datapub.gfz.de/download/dataset/');

    expect($result['raw_evidence']['files'])->toHaveCount(1)
        ->and($result['raw_evidence']['files'][0]['file_url'])->toBe('https://example.org/archive.zip')
        ->and($result['raw_evidence']['files'][0])->toHaveKey('zip_probe_result')
        ->and(array_column($result['suggestions'], 'inferred_value'))->toContain('application/zip')
        ->and(array_column($result['suggestions'], 'inferred_value'))->toContain('text/csv');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'example.org'));
});

it('matches the Stepanov ZIP directory listing example from datapub', function () {
    $url = 'https://datapub.gfz.de/download/10.5880.FIDGEO.2026.067-KJhgvb/';
    $zipUrl = $url.'2026-067_Stepanov-et-al_data.zip';
    $zipData = sizeFormatZipFixtureData(sizeFormatProbeStepanovZipFiles());

    Http::fake([
        $url => Http::response(<<<'HTML'
            <a href="2026-067_Stepanov-et-al_data.zip">2026-067_Stepanov-et-al_data.zip</a> 2026-07-10 09:11 272K
            HTML),
        $zipUrl => Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->probeDirectoryListing($url);

    $formatSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    ));
    $formatsByValue = collect($formatSuggestions)->keyBy('inferred_value');
    $sizeSuggestions = array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'size',
    ));

    expect($result['raw_evidence']['files'])->toHaveCount(1)
        ->and($result['raw_evidence']['files'][0]['filename'])->toBe('2026-067_Stepanov-et-al_data.zip')
        ->and(array_column($formatSuggestions, 'inferred_value'))->toEqualCanonicalizing([
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
        ])
        ->and($formatsByValue['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']['evidence']['entry_count_for_format'])->toBe(9)
        ->and($formatsByValue['text/csv']['evidence']['entry_count_for_format'])->toBe(6)
        ->and($formatsByValue['text/csv']['evidence']['total_file_count'])->toBe(15)
        ->and($sizeSuggestions)->toHaveCount(1)
        ->and($sizeSuggestions[0]['inferred_value'])->toBe('343055 Uncompressed Primary Data Size [bytes]')
        ->and($sizeSuggestions[0]['confidence'])->toBe('high')
        ->and($sizeSuggestions[0]['evidence']['parsed_file_count'])->toBe(15)
        ->and($sizeSuggestions[0]['evidence']['total_file_count'])->toBe(15)
        ->and($sizeSuggestions[0]['evidence']['zip_archive_count'])->toBe(1)
        ->and($sizeSuggestions[0]['evidence']['zip_entry_count'])->toBe(15);

    Http::assertSentCount(2);
});

it('follows a public ZIP redirect after validating its target', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://datapub.gfz.de/download/redirect.zip' && $request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => '2048',
            ]);
        }

        if ($request->url() === 'https://datapub.gfz.de/download/redirect.zip' && $request->method() === 'GET') {
            return Http::response('', 302, [
                'Location' => 'https://example.org/redirected.zip',
            ]);
        }

        return Http::response(sizeFormatZipFixtureData(['redirected/data.csv' => 'csv']), 200, [
            'Content-Type' => 'application/zip',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/redirect.zip');

    expect($result['probe_method'])->toBe('ZIP_CONTENT_LISTING')
        ->and(array_column($result['suggestions'], 'inferred_value'))
        ->toContain('application/zip', 'text/csv');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'example.org'));
});

it('falls back to ZIP container metadata when the entry count exceeds the inspection cap', function () {
    $entryLimit = (int) (new ReflectionClass(SizeFormatFileProbeService::class))->getConstant('MAX_ZIP_ENTRY_COUNT');
    $files = [];

    for ($index = 0; $index <= $entryLimit; $index++) {
        $files['entries/'.str_pad((string) $index, 5, '0', STR_PAD_LEFT).'.csv'] = '';
    }

    $zipData = sizeFormatZipFixtureData($files);

    Http::fake(function (Request $request) use ($zipData) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) strlen($zipData),
            ]);
        }

        return Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/many-entries.zip');

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'application/zip',
            'confidence' => 'low',
        ])
        ->and(array_column($result['suggestions'], 'inferred_value'))->not->toContain('text/csv');

    Http::assertSentCount(2);
});

it('falls back to ZIP container metadata when direct ZIP inspection exceeds the size limit', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) (1024 * 1024 * 1024 + 1),
            ]);
        }

        return Http::response(sizeFormatZipFixtureData(['data.csv' => 'csv']), 200, [
            'Content-Type' => 'application/zip',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/huge.zip');

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'application/zip',
            'confidence' => 'low',
        ]);

    Http::assertSentCount(1);
});

it('falls back to ZIP container metadata when direct ZIP inspection cannot read the archive', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => '12',
            ]);
        }

        return Http::response('not-a-zip', 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => '12',
        ]);
    });

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/broken.zip');

    expect($result['probe_method'])->toBe('HTTP_HEAD')
        ->and($result['suggestions'][0])->toMatchArray([
            'type' => 'format',
            'inferred_value' => 'application/zip',
            'confidence' => 'low',
        ]);

    Http::assertSentCount(2);
});

it('does not recursively inspect nested ZIP entries', function () {
    $nestedZipData = sizeFormatZipFixtureData([
        'nested/data.json' => '{"ok":true}',
    ]);
    $zipData = sizeFormatZipFixtureData([
        'outer/data.csv' => 'csv',
        'outer/nested.zip' => $nestedZipData,
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/nested.zip' => Http::response($zipData, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($zipData),
        ]),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/nested.zip');

    $formatValues = array_column(array_values(array_filter(
        $result['suggestions'],
        fn (array $suggestion): bool => $suggestion['type'] === 'format',
    )), 'inferred_value');

    expect($formatValues)->toEqualCanonicalizing([
        'text/csv',
        'application/zip',
    ])
        ->and($formatValues)->not->toContain('application/json');
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
            'confidence' => 'low',
        ])
        ->and($result['suggestions'][1])->toMatchArray([
            'type' => 'size',
            'inferred_value' => '4096 Primary Data Size [bytes]',
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
        ->and($result['probe_complete'])->toBeFalse()
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]['inferred_value'])->toBe('application/zip');

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Range', ['bytes=0-1023']),
    );
});

it('falls back to compressed filename extensions when remote metadata is unavailable', function () {
    Http::fake([
        'https://datapub.gfz.de/download/export.csv.gz' => Http::response('', 404),
    ]);

    $service = app(SizeFormatFileProbeService::class);
    $result = $service->inferMetadataFromFileUrl('https://datapub.gfz.de/download/export.csv.gz');

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK')
        ->and($result['probe_complete'])->toBeFalse()
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
                        'exact_size_bytes' => 1073741824,
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
        ->and($sizeSuggestions)->toBeEmpty();
});
