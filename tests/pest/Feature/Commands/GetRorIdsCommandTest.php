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

it('fails when the metadata request is unsuccessful', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response([], 503),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('Failed to fetch ROR metadata')
        ->assertExitCode(1);
});
