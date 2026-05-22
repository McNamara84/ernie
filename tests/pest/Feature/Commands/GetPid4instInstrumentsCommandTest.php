<?php

declare(strict_types=1);

use App\Console\Commands\GetPid4instInstruments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

covers(GetPid4instInstruments::class);

it('fetches and stores pid4inst instruments without sending the rejected sort parameter', function (): void {
    Storage::fake('local');

    Http::fakeSequence('https://b2inst.gwdg.de/api/records*')
        ->push([
            'hits' => [
                'total' => 3,
                'hits' => [
                    [
                        'id' => 'inst-1',
                        'metadata' => [
                            'Identifier' => [
                                'identifierType' => 'Handle',
                                'identifierValue' => '21.T11148/alpha',
                            ],
                            'Name' => 'Alpha Instrument',
                            'Description' => 'Primary test instrument',
                            'LandingPage' => 'https://example.org/instruments/alpha',
                            'Owner' => [
                                ['ownerName' => 'GFZ'],
                            ],
                            'Manufacturer' => [
                                ['manufacturerName' => 'Acme'],
                            ],
                            'Model' => [
                                'modelName' => 'A-1',
                            ],
                            'InstrumentType' => [
                                ['instrumentTypeName' => 'Spectrometer'],
                            ],
                            'MeasuredVariable' => ['Temperature'],
                        ],
                    ],
                    [
                        'id' => 'inst-2',
                        'metadata' => [
                            'Identifier' => [
                                'identifierType' => 'DOI',
                                'identifierValue' => '10.1234/example',
                            ],
                            'Name' => 'Beta Instrument',
                            'Description' => 'Second test instrument',
                            'LandingPage' => 'https://example.org/instruments/beta',
                            'Owner' => [
                                ['ownerName' => 'UFZ'],
                            ],
                            'Manufacturer' => [
                                ['manufacturerName' => 'Contoso'],
                            ],
                            'InstrumentType' => [
                                ['instrumentTypeName' => 'Sensor'],
                            ],
                            'MeasuredVariable' => ['Pressure'],
                        ],
                    ],
                ],
            ],
        ], 200)
        ->push([
            'hits' => [
                'total' => 3,
                'hits' => [
                    [
                        'id' => 'inst-3',
                        'metadata' => [
                            'Identifier' => [
                                'identifierType' => 'ARK',
                                'identifierValue' => 'ark:/12345/example',
                            ],
                            'Name' => 'Gamma Instrument',
                            'Description' => '',
                            'LandingPage' => '',
                            'Owner' => [],
                            'Manufacturer' => [],
                            'InstrumentType' => [],
                            'MeasuredVariable' => [],
                        ],
                    ],
                ],
            ],
        ], 200);

    $exitCode = Artisan::call('get-pid4inst-instruments');

    expect($exitCode)->toBe(0);
    Storage::assertExists('pid4inst-instruments.json');

    $payload = json_decode(Storage::get('pid4inst-instruments.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKeys(['lastUpdated', 'total', 'data'])
        ->and($payload['total'])->toBe(3)
        ->and($payload['data'])->toHaveCount(3)
        ->and($payload['data'][0])->toMatchArray([
            'id' => 'inst-1',
            'pid' => '21.T11148/alpha',
            'pidType' => 'Handle',
            'name' => 'Alpha Instrument',
            'description' => 'Primary test instrument',
            'landingPage' => 'https://example.org/instruments/alpha',
            'owners' => ['GFZ'],
            'manufacturers' => ['Acme'],
            'model' => 'A-1',
            'instrumentTypes' => ['Spectrometer'],
            'measuredVariables' => ['Temperature'],
        ])
        ->and($payload['data'][1]['pidType'])->toBe('DOI')
        ->and($payload['data'][2]['pidType'])->toBe('Handle')
        ->and($payload['data'][2]['model'])->toBeNull();

    Http::assertSentCount(2);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://b2inst.gwdg.de/api/records?')
            && ! array_key_exists('sort', $query)
            && isset($query['size'], $query['page']);
    });
});

it('fails without creating a registry file when b2inst rejects the request', function (): void {
    Storage::fake('local');

    Http::fake([
        'https://b2inst.gwdg.de/api/records*' => Http::response([
            'errors' => [
                ['message' => 'Invalid sort parameter'],
            ],
        ], 400),
    ]);

    $exitCode = Artisan::call('get-pid4inst-instruments');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Failed to fetch page 1: HTTP 400');

    Storage::assertMissing('pid4inst-instruments.json');
});