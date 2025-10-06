<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('fetches and stores ROR affiliation suggestions', function () {
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

    // Create a ZIP file with JSON content
    $jsonContent = json_encode($organizations, JSON_THROW_ON_ERROR);
    $tempZipPath = tempnam(sys_get_temp_dir(), 'ror-test-');
    $zip = new ZipArchive;
    $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('v1.0-2024-01-01-ror-data.json', $jsonContent);
    $zip->close();

    $zipData = file_get_contents($tempZipPath);
    unlink($tempZipPath);

    $metadata = [
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

    Http::fake([
        'https://zenodo.org/api/records*' => Http::response($metadata, 200),
        'https://example.org/ror-data-latest.zip' => Http::response($zipData, 200),
    ]);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-ror-affiliations.json');
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-ror-ids', ['--output' => $outputPath])
        ->assertExitCode(0);

    expect(File::exists($outputPath))->toBeTrue();

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        [
            'prefLabel' => 'Example University',
            'rorId' => 'https://ror.org/123456789',
            'otherLabel' => [
                'Example University',
                'University of Examples',
                'EU',
                'Beispiel Universität',
            ],
        ],
        [
            'prefLabel' => 'Sample Institute',
            'rorId' => 'https://ror.org/987654321',
            'otherLabel' => ['Sample Institute'],
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
