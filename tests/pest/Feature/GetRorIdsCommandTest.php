<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('fetches and stores ROR affiliation suggestions', function () {
    $metadata = [
        'hits' => [
            'hits' => [
                [
                    'files' => [
                        [
                            'key' => 'ror-data-latest.jsonl.gz',
                            'links' => [
                                'download' => 'https://example.org/ror-data-latest.jsonl.gz',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $organizations = [
        [
            'id' => 'https://ror.org/123456789',
            'name' => 'Example University',
            'aliases' => ['University of Examples'],
            'acronyms' => ['EU'],
            'labels' => [
                ['label' => 'Beispiel Universität'],
            ],
            'country' => [
                'country_name' => 'Germany',
                'country_code' => 'DE',
            ],
        ],
        [
            'id' => 'https://ror.org/987654321',
            'name' => 'Sample Institute',
            'aliases' => [],
            'acronyms' => [],
            'labels' => [],
            'country' => [
                'country_name' => 'Switzerland',
                'country_code' => 'CH',
            ],
        ],
    ];

    $jsonLines = implode("\n", array_map(
        static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
        $organizations,
    ));
    $gzData = gzencode($jsonLines);

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response($metadata, 200),
        'https://example.org/ror-data-latest.jsonl.gz' => Http::response($gzData, 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->assertExitCode(0);

    expect(File::exists($outputPath))->toBeTrue();

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        [
            'value' => 'Example University',
            'rorId' => 'https://ror.org/123456789',
            'country' => 'Germany',
            'countryCode' => 'DE',
            'searchTerms' => [
                'Example University',
                'University of Examples',
                'EU',
                'Beispiel Universität',
            ],
        ],
        [
            'value' => 'Sample Institute',
            'rorId' => 'https://ror.org/987654321',
            'country' => 'Switzerland',
            'countryCode' => 'CH',
            'searchTerms' => ['Sample Institute'],
        ],
    ]);

    File::delete($outputPath);
});

it('fails when the metadata request is unsuccessful', function () {
    Http::fake([
        'https://zenodo.org/api/records*' => Http::response([], 503),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutputToContain('Failed to fetch ROR metadata')
        ->assertExitCode(1);
});
