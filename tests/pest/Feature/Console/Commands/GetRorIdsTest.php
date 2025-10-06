<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
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

it('handles missing files in Zenodo record', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [], // No files
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutput('Unable to locate a data dump within the ROR record.')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

it('handles missing download URL', function (): void {
    Http::fake([
        'zenodo.org/api/records/*' => Http::response([
            'hits' => [
                'hits' => [
                    [
                        'files' => [
                            [
                                'key' => 'v1.52-2024-09-16-ror-data.zip',
                                'links' => [], // Missing 'self' link
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('get-ror-ids')
        ->expectsOutput('The ROR data dump is missing a download URL.')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

it('handles failed download', function (): void {
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
        'zenodo.org/api/files/test/ror-data.zip' => Http::response('', 500),
    ]);

    $this->artisan('get-ror-ids')
        ->assertExitCode(1);

    Storage::disk('local')->assertMissing('ror/ror-affiliations.json');
});

