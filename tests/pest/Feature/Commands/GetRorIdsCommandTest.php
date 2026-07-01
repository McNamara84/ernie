<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function getRorIdsCommandZipData(array $organizations): string
{
    $jsonContent = json_encode($organizations, JSON_THROW_ON_ERROR);
    $tempZipPath = tempnam(sys_get_temp_dir(), 'ror-test-');
    $zip = new ZipArchive;
    $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('v1.0-2024-01-01-ror-data.json', $jsonContent);
    $zip->close();

    $zipData = file_get_contents($tempZipPath);
    unlink($tempZipPath);

    if ($zipData === false) {
        throw new RuntimeException('Failed to read test ZIP data.');
    }

    return $zipData;
}

function getRorIdsCommandGzipData(array $organizations): string
{
    $jsonLines = implode("\n", array_map(
        fn (array $organization): string => json_encode($organization, JSON_THROW_ON_ERROR),
        $organizations,
    ));
    $tempGzipPath = tempnam(sys_get_temp_dir(), 'ror-test-gzip-');
    $gzip = gzopen($tempGzipPath, 'wb');

    if ($gzip === false) {
        throw new RuntimeException('Failed to open test GZIP data.');
    }

    gzwrite($gzip, $jsonLines);
    gzclose($gzip);

    $gzipData = file_get_contents($tempGzipPath);
    unlink($tempGzipPath);

    if ($gzipData === false) {
        throw new RuntimeException('Failed to read test GZIP data.');
    }

    return $gzipData;
}

function getRorIdsCommandGzipMetadata(): array
{
    return [
        'hits' => [
            'hits' => [
                [
                    'files' => [
                        [
                            'key' => 'v1.0-2024-01-01-ror-data.jsonl.gz',
                            'links' => [
                                'self' => 'https://example.org/ror-data-latest.jsonl.gz',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
function getRorIdsCommandMetadata(): array
{
    return [
        'hits' => [
            'hits' => [
                [
                    'files' => [
                        [
                            'key' => 'v1.0-2024-01-01-ror-data.zip',
                            'links' => [
                                'self' => 'https://example.org/ror-data-latest.zip',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

it('fetches and stores ROR affiliation suggestions and FundRef index entries', function () {
    $organizations = [
        [
            'id' => 'https://ror.org/018mejw64',
            'name' => 'Deutsche Forschungsgemeinschaft',
            'aliases' => ['German Research Foundation'],
            'acronyms' => ['DFG'],
            'labels' => [
                ['label' => 'Deutsche Forschungsgemeinschaft'],
            ],
            'status' => 'active',
            'types' => ['funder', 'nonprofit'],
            'external_ids' => [
                'FundRef' => [
                    'preferred' => '501100001659',
                    'all' => ['501100001659'],
                ],
            ],
            'country' => [
                'country_name' => 'Germany',
                'country_code' => 'DE',
            ],
        ],
        [
            'id' => 'https://ror.org/04z8jg394',
            'name' => 'GFZ Helmholtz Centre for Geosciences',
            'aliases' => [],
            'acronyms' => [],
            'labels' => [],
            'status' => 'active',
            'types' => ['facility', 'funder'],
            'external_ids' => [
                'FundRef' => [
                    'preferred' => '501100010956',
                    'all' => ['501100010956'],
                ],
            ],
            'country' => [
                'country_name' => 'Germany',
                'country_code' => 'DE',
            ],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadata(), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipData($organizations), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->assertExitCode(0);

    expect(File::exists($outputPath))->toBeTrue()
        ->and(File::exists($fundrefIndexPath))->toBeTrue();

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
    $fundrefIndex = json_decode(File::get($fundrefIndexPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['lastUpdated', 'data', 'total'])
        ->and($decoded['total'])->toBe(2)
        ->and($decoded['data'])->toBe([
            [
                'prefLabel' => 'Deutsche Forschungsgemeinschaft',
                'rorId' => 'https://ror.org/018mejw64',
                'otherLabel' => [
                    'Deutsche Forschungsgemeinschaft',
                    'German Research Foundation',
                    'DFG',
                ],
            ],
            [
                'prefLabel' => 'GFZ Helmholtz Centre for Geosciences',
                'rorId' => 'https://ror.org/04z8jg394',
                'otherLabel' => ['GFZ Helmholtz Centre for Geosciences'],
            ],
        ])
        ->and($fundrefIndex)->toHaveKeys(['lastUpdated', 'source', 'data', 'total'])
        ->and($fundrefIndex['total'])->toBe(2)
        ->and($fundrefIndex['data'][0]['ror_id'])->toBe('https://ror.org/018mejw64')
        ->and($fundrefIndex['data'][0]['external_ids']['fundref']['all'])->toBe(['501100001659'])
        ->and($fundrefIndex['data'][1]['ror_id'])->toBe('https://ror.org/04z8jg394')
        ->and($fundrefIndex['data'][1]['external_ids']['fundref']['all'])->toBe(['501100010956'])
        ->and($fundrefIndex['source']['source_file'])->toBe('ror/ror-fundref-index.json');

    File::delete([$outputPath, $fundrefIndexPath]);
});

it('fetches gzipped JSONL ROR data and stores both derived outputs', function () {
    $organizations = [
        [
            'id' => 'https://ror.org/018mejw64',
            'name' => 'Deutsche Forschungsgemeinschaft',
            'aliases' => ['German Research Foundation'],
            'acronyms' => ['DFG'],
            'labels' => [
                ['label' => 'Deutsche Forschungsgemeinschaft'],
            ],
            'status' => 'active',
            'types' => ['funder', 'nonprofit'],
            'external_ids' => [
                'FundRef' => [
                    'preferred' => '501100001659',
                    'all' => ['501100001659'],
                ],
            ],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandGzipMetadata(), 200),
        'https://example.org/ror-data-latest.jsonl.gz' => Http::response(getRorIdsCommandGzipData($organizations), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->assertExitCode(0);

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
    $fundrefIndex = json_decode(File::get($fundrefIndexPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['data'])->toBe([
        [
            'prefLabel' => 'Deutsche Forschungsgemeinschaft',
            'rorId' => 'https://ror.org/018mejw64',
            'otherLabel' => [
                'Deutsche Forschungsgemeinschaft',
                'German Research Foundation',
                'DFG',
            ],
        ],
    ])
        ->and($fundrefIndex['total'])->toBe(1)
        ->and($fundrefIndex['data'][0]['ror_id'])->toBe('https://ror.org/018mejw64')
        ->and($fundrefIndex['data'][0]['external_ids']['fundref']['all'])->toBe(['501100001659']);

    File::delete([$outputPath, $fundrefIndexPath]);
});
it('does not replace affiliations when the FundRef index cannot be moved into place', function () {
    $organizations = [
        [
            'id' => 'https://ror.org/018mejw64',
            'name' => 'Deutsche Forschungsgemeinschaft',
            'status' => 'active',
            'types' => ['funder'],
            'external_ids' => [
                'FundRef' => [
                    'preferred' => '501100001659',
                    'all' => ['501100001659'],
                ],
            ],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadata(), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipData($organizations), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));
    File::put($outputPath, 'old affiliations');
    File::makeDirectory($fundrefIndexPath);

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->expectsOutputToContain('Failed to process ROR data dump')
        ->assertExitCode(1);

    expect(File::get($outputPath))->toBe('old affiliations');

    File::delete($outputPath);
    File::deleteDirectory($fundrefIndexPath);
});

it('does not replace the FundRef index when affiliations cannot be moved into place', function () {
    $organizations = [
        [
            'id' => 'https://ror.org/018mejw64',
            'name' => 'Deutsche Forschungsgemeinschaft',
            'status' => 'active',
            'types' => ['funder'],
            'external_ids' => [
                'FundRef' => [
                    'preferred' => '501100001659',
                    'all' => ['501100001659'],
                ],
            ],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadata(), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipData($organizations), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));
    File::makeDirectory($outputPath);
    File::put($fundrefIndexPath, 'old fundref index');

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->expectsOutputToContain('Failed to process ROR data dump')
        ->assertExitCode(1);

    expect(File::get($fundrefIndexPath))->toBe('old fundref index');

    File::deleteDirectory($outputPath);
    File::delete($fundrefIndexPath);
});
it('fails when the metadata request is unsuccessful', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response([], 503),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('Failed to fetch ROR metadata')
        ->assertExitCode(1);
});
function getRorIdsCommandRawGzipData(array $lines): string
{
    $tempGzipPath = tempnam(sys_get_temp_dir(), 'ror-test-raw-gzip-');
    $gzip = gzopen($tempGzipPath, 'wb');

    if ($gzip === false) {
        throw new RuntimeException('Failed to open raw test GZIP data.');
    }

    gzwrite($gzip, implode("\n", $lines));
    gzclose($gzip);

    $gzipData = file_get_contents($tempGzipPath);
    unlink($tempGzipPath);

    if ($gzipData === false) {
        throw new RuntimeException('Failed to read raw test GZIP data.');
    }

    return $gzipData;
}

function getRorIdsCommandMetadataForFile(string $key, string $url = 'https://example.org/ror-data-latest.zip'): array
{
    return [
        'hits' => [
            'hits' => [
                [
                    'files' => [
                        [
                            'key' => $key,
                            'links' => [
                                'self' => $url,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function getRorIdsCommandZipWithoutJsonData(): string
{
    $tempZipPath = tempnam(sys_get_temp_dir(), 'ror-test-no-json-');
    $zip = new ZipArchive;
    $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('README.txt', 'No ROR data here.');
    $zip->close();

    $zipData = file_get_contents($tempZipPath);
    unlink($tempZipPath);

    if ($zipData === false) {
        throw new RuntimeException('Failed to read no-json ZIP data.');
    }

    return $zipData;
}

it('processes schema v2 ROR names and list-style FundRef external identifiers', function () {
    $organizations = [
        [
            'id' => 'HTTP://WWW.ROR.ORG/018MEJW64/',
            'names' => [
                ['value' => 'Ignored Alias', 'types' => ['alias']],
                ['value' => 'Schema v2 Display Name', 'types' => ['ror_display']],
                ['value' => 'Schema v2 Label Name', 'types' => ['label']],
                'not-a-name-entry',
            ],
            'aliases' => ['Legacy Alias', ''],
            'acronyms' => ['DFG'],
            'labels' => [
                ['label' => 'German Label'],
                ['ignored' => 'missing label'],
            ],
            'status' => 'active',
            'types' => ['funder', 123, ''],
            'updated' => '2026-01-02T00:00:00Z',
            'external_ids' => [
                ['type' => 'FundRef', 'all' => '501100001659', 'preferred' => 'not-numeric'],
                ['type' => 'GRID', 'all' => ['grid.12345.6']],
            ],
        ],
        [
            'id' => 'https://ror.org/04z8jg394',
            'names' => [
                ['value' => 'Schema v2 Label Fallback', 'types' => ['label']],
            ],
            'types' => 'not-an-array',
            'last_modified' => '2026-02-03T00:00:00Z',
            'external_ids' => [
                'FundRef ID' => [
                    'all' => ['501100010956', '501100010956', 'bad-value'],
                    'preferred' => '501100010956',
                ],
            ],
        ],
        [
            'id' => 'https://ror.org/03yrm5c26',
            'names' => [],
            'external_ids' => [],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadata(), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipData($organizations), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->assertExitCode(0);

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
    $fundrefIndex = json_decode(File::get($fundrefIndexPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['total'])->toBe(2)
        ->and($decoded['data'][0]['prefLabel'])->toBe('Schema v2 Display Name')
        ->and($decoded['data'][0]['otherLabel'])->toContain('Ignored Alias')
        ->and($decoded['data'][0]['otherLabel'])->toContain('Schema v2 Label Name')
        ->and($decoded['data'][0]['otherLabel'])->toContain('Legacy Alias')
        ->and($decoded['data'][0]['otherLabel'])->toContain('DFG')
        ->and($decoded['data'][0]['otherLabel'])->toContain('German Label')
        ->and($decoded['data'][1]['prefLabel'])->toBe('Schema v2 Label Fallback')
        ->and($fundrefIndex['total'])->toBe(2)
        ->and($fundrefIndex['data'][0]['ror_id'])->toBe('https://ror.org/018mejw64')
        ->and($fundrefIndex['data'][0]['ror_types'])->toBe(['funder', '123'])
        ->and($fundrefIndex['data'][0]['ror_record_last_modified'])->toBe('2026-01-02T00:00:00Z')
        ->and($fundrefIndex['data'][0]['external_ids']['fundref'])->toBe([
            'all' => ['501100001659'],
            'preferred' => null,
        ])
        ->and($fundrefIndex['data'][1]['ror_types'])->toBe([])
        ->and($fundrefIndex['data'][1]['ror_record_last_modified'])->toBe('2026-02-03T00:00:00Z')
        ->and($fundrefIndex['data'][1]['external_ids']['fundref'])->toBe([
            'all' => ['501100010956'],
            'preferred' => '501100010956',
        ]);

    File::delete([$outputPath, $fundrefIndexPath]);
});

it('skips malformed JSONL rows while processing gzipped ROR dumps', function () {
    $validOrganization = [
        'id' => 'https://ror.org/018mejw64',
        'name' => 'Deutsche Forschungsgemeinschaft',
        'status' => 'active',
        'types' => ['funder'],
        'external_ids' => [
            'FundRef' => [
                'preferred' => '501100001659',
                'all' => ['501100001659'],
            ],
        ],
    ];

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandGzipMetadata(), 200),
        'https://example.org/ror-data-latest.jsonl.gz' => Http::response(getRorIdsCommandRawGzipData([
            '{malformed-json',
            json_encode($validOrganization, JSON_THROW_ON_ERROR),
            '',
            json_encode('not-an-organization', JSON_THROW_ON_ERROR),
        ]), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    $fundrefIndexPath = dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->expectsOutputToContain('Skipping malformed JSON line')
        ->assertExitCode(0);

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
    $fundrefIndex = json_decode(File::get($fundrefIndexPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['total'])->toBe(1)
        ->and($fundrefIndex['total'])->toBe(1);

    File::delete([$outputPath, $fundrefIndexPath]);
});

it('fails when Zenodo returns no ROR dump records', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(['hits' => ['hits' => []]], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('No ROR data dump records were returned.')
        ->assertExitCode(1);
});

it('fails when the ROR record does not contain a supported dump file', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            ['key' => 'readme.txt'],
                            'not-a-file-entry',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('Unable to locate a data dump within the ROR record.')
        ->assertExitCode(1);
});

it('fails when the selected ROR dump file is missing a download URL', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            ['key' => 'v1.0-2024-01-01-ror-data.zip'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('The ROR data dump is missing a download URL.')
        ->assertExitCode(1);
});

it('fails when the ROR dump download is unsuccessful', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadataForFile('v1.0-2024-01-01-ror-data.zip'), 200),
        'https://example.org/ror-data-latest.zip' => Http::response('unavailable', 503),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('Failed to download ROR data dump')
        ->assertExitCode(1);
});

it('fails when a ZIP ROR dump does not contain a JSON file', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadataForFile('v1.0-2024-01-01-ror-data.zip'), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipWithoutJsonData(), 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('No JSON file found in the ROR data archive.')
        ->assertExitCode(1);
});

it('fails when a downloaded ROR JSON dump contains no valid organizations', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response(getRorIdsCommandMetadataForFile('v1.0-2024-01-01-ror-data.zip'), 200),
        'https://example.org/ror-data-latest.zip' => Http::response(getRorIdsCommandZipData([
            ['id' => '', 'name' => 'Missing ID'],
            ['id' => 'https://ror.org/018mejw64', 'names' => []],
        ]), 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->expectsOutputToContain('No ROR affiliations were written.')
        ->assertExitCode(1);

    File::delete([
        $outputPath,
        dirname($outputPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json',
    ]);
});
