<?php

declare(strict_types=1);

use App\Console\Commands\GetPid4instInstruments;
use App\Services\Pid4instVocabularyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetPid4instInstruments::class);

function pid4instHost(): string
{
    return rtrim((string) config('b2inst.host'), '/');
}

function pid4instRecordsUrlPattern(): string
{
    return pid4instHost().'/api/records*';
}

beforeEach(function (): void {
    config(['b2inst.host' => 'https://b2inst.example.test']);
});

it('fetches and stores pid4inst instruments without sending the rejected sort parameter', function (): void {
    Storage::fake('local');

    Http::fakeSequence(pid4instRecordsUrlPattern())
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
            && str_starts_with($request->url(), pid4instHost().'/api/records?')
            && ! array_key_exists('sort', $query)
            && isset($query['size'], $query['page']);
    });

    Http::assertNotSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), pid4instHost().'/api/records?')
            && array_key_exists('sort', $query);
    });
});

it('fails without creating a registry file when b2inst rejects the request', function (): void {
    Storage::fake('local');

    Http::fake([
        pid4instRecordsUrlPattern() => Http::response([
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

it('preserves explicit conflicting identifiers for local resolution but not public responses', function () {
    Storage::fake('local');
    Http::fake([pid4instRecordsUrlPattern() => Http::response(['hits' => ['total' => 1, 'hits' => [[
        'id' => 'epmy8-2k126',
        'metadata' => ['Name' => 'GNSS receiver', 'Identifier' => ['identifierType' => 'Handle', 'identifierValue' => '21.11157/epmy8-2k126']],
        'pids' => ['epic' => ['identifier' => 'http://hdl.handle.net/21.11157/uuid']],
    ]]]])]);
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(0);
    $vocabulary = app(Pid4instVocabularyService::class);
    expect($vocabulary->getLocalPayload()['data'][0]['lookupIdentifiers'])->toBe([
        ['value' => '21.11157/epmy8-2k126', 'type' => 'Handle', 'source' => 'metadata'],
        ['value' => 'http://hdl.handle.net/21.11157/uuid', 'type' => 'Handle', 'source' => 'epic'],
    ])->and($vocabulary->getPublicPayload()['data'][0])->not->toHaveKey('lookupIdentifiers');
});

it('preserves the previous registry and cache after an incomplete or invalid download', function (string $scenario) {
    Storage::fake('local');
    $vocabulary = app(Pid4instVocabularyService::class);
    $previous = json_encode(['data' => [['id' => 'old', 'pid' => '21.test/old', 'pidType' => 'Handle', 'name' => 'Old instrument']]]);
    $vocabulary->replace($previous);
    $cached = $vocabulary->getLocalPayload();
    $hit = ['id' => 'one', 'metadata' => ['Name' => 'New instrument', 'Identifier' => ['identifierValue' => '21.test/new']]];
    if ($scenario === 'network') {
        Http::fake([pid4instRecordsUrlPattern() => Http::failedConnection()]);
    } else {
        $sequence = Http::fakeSequence(pid4instRecordsUrlPattern());
        match ($scenario) {
            'truncated' => $sequence->push(['hits' => ['total' => 2, 'hits' => [$hit]]])->push(['hits' => ['total' => 2, 'hits' => []]]),
            'changing' => $sequence->push(['hits' => ['total' => 2, 'hits' => [$hit]]])->push(['hits' => ['total' => 3, 'hits' => []]]),
            'duplicate' => $sequence->push(['hits' => ['total' => 2, 'hits' => [$hit, $hit]]]),
            'overfull' => $sequence->push(['hits' => ['total' => 0, 'hits' => [$hit]]]),
            'malformed' => $sequence->push(['hits' => ['total' => '2', 'hits' => []]]),
            'invalid record' => $sequence->push(['hits' => ['total' => 1, 'hits' => [['id' => 'broken']]]]),
            'http' => $sequence->push([], 503),
        };
    }
    expect(Artisan::call('get-pid4inst-instruments'))->toBe(1)
        ->and(Storage::get(Pid4instVocabularyService::FILE))->toBe($previous)
        ->and($vocabulary->getLocalPayload())->toBe($cached);
})->with(['truncated', 'changing', 'duplicate', 'overfull', 'malformed', 'invalid record', 'http', 'network']);
