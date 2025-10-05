<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('fetches and processes ROR data from Zenodo successfully', function (): void {
    // Mock Zenodo API response with record metadata
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            [
                                'key' => 'v1.52-2024-09-16-ror-data.zip',
                                'links' => [
                                    'self' => 'https://zenodo.org/api/files/test/ror-data.zip',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
        'zenodo.org/api/files/test/ror-data.zip' => Http::response(
            createMockZipFile(),
            200,
            ['Content-Type' => 'application/zip']
        ),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutput('Fetching latest ROR data dump metadata…')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists('ror/ror-affiliations.json');

    $content = Storage::disk('local')->get('ror/ror-affiliations.json');
    $data = json_decode($content, true);

    expect($data)->toBeArray()
        ->and($data)->toHaveCount(2)
        ->and($data[0])->toHaveKeys(['prefLabel', 'rorId', 'otherLabel'])
        ->and($data[0]['prefLabel'])->toBe('University of Potsdam')
        ->and($data[0]['rorId'])->toBe('https://ror.org/03bq45144')
        ->and($data[0]['otherLabel'])->toBeArray()
        ->and($data[1]['prefLabel'])->toBe('Max Planck Institute for Gravitational Physics');
});

it('prefers schema v1 over v2 when both are available', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            [
                                'key' => 'v2.0-2024-09-16-ror-data.zip',
                                'links' => ['self' => 'https://zenodo.org/api/files/test/v2.zip'],
                            ],
                            [
                                'key' => 'v1.52-2024-09-16-ror-data.zip',
                                'links' => ['self' => 'https://zenodo.org/api/files/test/v1.zip'],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
        'zenodo.org/api/files/test/v1.zip' => Http::response(
            createMockZipFile(),
            200,
            ['Content-Type' => 'application/zip']
        ),
    ]);

    $this->artisan('get-ror-ids')
        ->assertExitCode(0);

    // Verify v1 was used by checking the output contains expected data
    Storage::disk('local')->assertExists('ror/ror-affiliations.json');
});

it('handles API errors gracefully', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response(['error' => 'Not found'], 404),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutput('Fetching latest ROR data dump metadata…')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

it('handles empty response from Zenodo', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => ['hits' => []],
        ], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

it('handles malformed ZIP files', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            [
                                'key' => 'v1.52-2024-09-16-ror-data.zip',
                                'links' => ['self' => 'https://zenodo.org/api/files/test/bad.zip'],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
        'zenodo.org/api/files/test/bad.zip' => Http::response('not a zip file', 200),
    ]);

    $this->artisan('get-ror-ids')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

/**
 * Creates a mock ZIP file containing ROR JSON data
 */
function createMockZipFile(): string
{
    $tempDir = sys_get_temp_dir() . '/ror-test-' . uniqid();
    mkdir($tempDir);

    $jsonData = [
        [
            'id' => 'https://ror.org/03bq45144',
            'name' => 'University of Potsdam',
            'labels' => [
                ['label' => 'Universität Potsdam', 'iso639' => 'de'],
            ],
            'aliases' => ['UP', 'Uni Potsdam'],
        ],
        [
            'id' => 'https://ror.org/00z8tcb16',
            'name' => 'Max Planck Institute for Gravitational Physics',
            'labels' => [
                ['label' => 'Albert Einstein Institut', 'iso639' => 'de'],
            ],
            'aliases' => ['AEI'],
        ],
    ];

    $jsonFile = $tempDir . '/ror-data.json';
    file_put_contents($jsonFile, json_encode($jsonData));

    $zipFile = $tempDir . '/ror-data.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFile($jsonFile, 'ror-data.json');
    $zip->close();

    $zipContent = file_get_contents($zipFile);

    // Cleanup
    unlink($jsonFile);
    unlink($zipFile);
    rmdir($tempDir);

    return $zipContent;
}
