<?php

declare(strict_types=1);

use App\Exceptions\VocabularyReadException;
use App\Models\PidSetting;
use App\Services\InstrumentNameResolutionService;
use App\Services\Pid4instVocabularyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    Storage::put(Pid4instVocabularyService::FILE, file_get_contents(base_path('tests/pest/Fixtures/pid4inst/issue-1498.json')));
    PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PID4INST', 'is_active' => true]);
});

it('resolves equivalent forms without changing their identifiers', function (string $pid, string $name) {
    $result = app(InstrumentNameResolutionService::class)->resolve([['pid' => $pid, 'pidType' => 'Handle', 'name' => $name]]);
    expect($result[0])->toMatchArray(['pid' => $pid, 'pidType' => 'Handle', 'name' => 'Green Roof Measurements MDR', 'resolution' => ['status' => 'matched']]);
    Http::assertNothingSent();
})->with([
    ['21.11157/3f8xq-m8w44', '21.11157/3f8xq-m8w44'],
    ['https://hdl.handle.net/21.11157/3f8xq-m8w44', '21.11157/3f8xq-m8w44'],
    ['http://hdl.handle.net/21.11157/3f8xq-m8w44?x=1#part', ''],
    [' 21.11157/3f8xq-m8w44 ', ' https://hdl.handle.net/21.11157/3f8xq-m8w44 '],
]);

it('uses only explicit typed identifiers and never record id guesses', function (string $pid, string $type) {
    $result = app(InstrumentNameResolutionService::class)->resolve([['pid' => $pid, 'pidType' => $type, 'name' => $pid]]);
    expect($result[0]['name'])->toBe($pid)->and($result[0]['resolution']['status'])->toBe('not_found');
})->with([
    ['21.11157/3f8xq-m8w44', 'DOI'],
    ['https://example.org/21.11157/3f8xq-m8w44', 'Handle'],
    ['https://user@hdl.handle.net/21.11157/3f8xq-m8w44', 'Handle'],
    ['https://hdl.handle.net:8080/21.11157/3f8xq-m8w44', 'Handle'],
    ['21.11157/pyfqn-93022', 'Handle'],
    ['21.11157/3F8XQ-M8W44', 'Handle'],
]);

it('resolves DOI case variants and placeholders without changing identifiers or curated names', function (string $pid, string $name, string $expectedName) {
    $record = [
        'pid' => '10.1234/Example', 'pidType' => 'DOI', 'name' => 'Research instrument',
        'lookupIdentifiers' => [
            ['value' => '10.1234/Example', 'type' => 'DOI', 'source' => 'metadata'],
            ['value' => 'https://doi.org/10.1234/EXAMPLE', 'type' => 'DOI', 'source' => 'provider'],
        ],
    ];
    app(Pid4instVocabularyService::class)->replace(json_encode(['data' => [$record]]));
    $resolved = app(InstrumentNameResolutionService::class)->resolve([['pid' => $pid, 'pidType' => 'DOI', 'name' => $name]]);
    expect($resolved[0])->toBe(['pid' => $pid, 'pidType' => 'DOI', 'name' => $expectedName, 'resolution' => ['status' => 'matched']])
        ->and(app(Pid4instVocabularyService::class)->getLocalPayload()['data'][0])->toBe($record);
    Http::assertNothingSent();
})->with([
    ['10.1234/example', '10.1234/EXAMPLE', 'Research instrument'],
    ['https://doi.org/10.1234/example', '10.1234/Example', 'Research instrument'],
    [' HTTP://DOI.ORG/10.1234/EXAMPLE ', '', 'Research instrument'],
    ['10.1234/example', 'Curated instrument name', 'Curated instrument name'],
]);

it('does not pick a registry record when DOI case variants are ambiguous', function () {
    app(Pid4instVocabularyService::class)->replace(json_encode(['data' => [
        ['pid' => '10.1234/Example', 'pidType' => 'DOI', 'name' => 'Instrument A'],
        ['pid' => '10.1234/EXAMPLE', 'pidType' => 'DOI', 'name' => 'Instrument B'],
    ]]));
    $input = ['pid' => 'https://doi.org/10.1234/example', 'pidType' => 'DOI', 'name' => '10.1234/example'];
    expect(app(InstrumentNameResolutionService::class)->resolve([$input])[0])
        ->toMatchArray($input + ['resolution' => ['status' => 'ambiguous']]);
});

it('preserves real DOI identifier conflicts and their original spelling', function () {
    $identifiers = [
        ['value' => '10.1234/Example', 'type' => 'DOI', 'source' => 'metadata'],
        ['value' => '10.1234/Different', 'type' => 'DOI', 'source' => 'provider'],
    ];
    app(Pid4instVocabularyService::class)->replace(json_encode(['data' => [[
        'pid' => '10.1234/Example', 'pidType' => 'DOI', 'name' => 'Research instrument', 'lookupIdentifiers' => $identifiers,
    ]]]));
    $resolved = app(InstrumentNameResolutionService::class)->resolve([
        ['pid' => 'https://doi.org/10.1234/example', 'pidType' => 'DOI', 'name' => '10.1234/EXAMPLE'],
    ]);
    expect($resolved[0])->toBe([
        'pid' => 'https://doi.org/10.1234/example', 'pidType' => 'DOI', 'name' => 'Research instrument',
        'resolution' => ['status' => 'identifier_conflict', 'identifiers' => $identifiers],
    ]);
});

it('reports conflicts through either explicit identifier while preserving real names', function (string $pid, string $name) {
    $result = app(InstrumentNameResolutionService::class)->resolve([['pid' => $pid, 'pidType' => 'Handle', 'name' => $name]]);
    expect($result[0]['pid'])->toBe($pid)
        ->and($result[0]['name'])->toBe($name === $pid ? 'GNSS_receiver_8512000319' : $name)
        ->and($result[0]['resolution']['status'])->toBe('identifier_conflict')
        ->and($result[0]['resolution']['identifiers'])->toHaveCount(2);
})->with([
    ['21.11157/epmy8-2k126', '21.11157/epmy8-2k126'],
    ['21.11157/957458ca-d31e-4041-95af-55b1ca251166', 'Curated name'],
]);

it('retains selections when the local registry is absent malformed or disabled', function (string $scenario) {
    if ($scenario === 'missing') {
        Storage::delete(Pid4instVocabularyService::FILE);
    } elseif ($scenario === 'disabled') {
        PidSetting::where('type', 'pid4inst')->update(['is_active' => false]);
    } else {
        Storage::put(Pid4instVocabularyService::FILE, $scenario);
    }
    $input = ['pid' => '21.11157/3f8xq-m8w44', 'pidType' => 'Handle', 'name' => 'Curated name'];
    $result = app(InstrumentNameResolutionService::class)->resolve([$input, $input]);
    expect($result)->toHaveCount(2)->and($result[0])->toMatchArray($input)
        ->and($result[0]['resolution']['status'])->toBe('unavailable');
    Http::assertNothingSent();
})->with(['missing', 'disabled', '', '{', 'null', '{"data":{}}', '{"data":[{"pid":5}]}', '{"data":[{"pid":"x","pidType":"Handle","name":"Name","lookupIdentifiers":[{}]}]}']);

it('does not choose between multiple records or use empty registry names', function (bool $ambiguous) {
    $record = ['id' => 'a', 'pid' => '21.test/a', 'pidType' => 'Handle', 'name' => $ambiguous ? 'Name' : ' '];
    $records = $ambiguous ? [$record, array_replace($record, ['id' => 'b', 'name' => 'Different'])] : [$record];
    Storage::put(Pid4instVocabularyService::FILE, json_encode(['data' => $records]));
    $result = app(InstrumentNameResolutionService::class)->resolve([['pid' => '21.test/a', 'pidType' => 'Handle', 'name' => '21.test/a']]);
    expect($result[0]['name'])->toBe('21.test/a')
        ->and($result[0]['resolution']['status'])->toBe($ambiguous ? 'ambiguous' : 'not_found');
})->with([true, false]);

it('invalidates cached snapshots and strips internal identifiers on every public read', function () {
    $service = app(Pid4instVocabularyService::class);
    expect($service->getPublicPayload()['data'][1])->not->toHaveKey('lookupIdentifiers');
    expect($service->getLocalPayload()['data'][1])->toHaveKey('lookupIdentifiers');
    $payload = $service->getLocalPayload();
    $payload['data'][0]['name'] = 'New name';
    $service->replace(json_encode($payload));
    expect($service->getPublicPayload()['data'][0]['name'])->toBe('New name')
        ->and($service->getPublicPayload()['data'][1])->not->toHaveKey('lookupIdentifiers');
});

it('reports conflicting identifiers even when the registry has no name', function () {
    $vocabulary = app(Pid4instVocabularyService::class);
    $payload = $vocabulary->getLocalPayload();
    $payload['data'][1]['name'] = '';
    $vocabulary->replace(json_encode($payload));
    $selection = ['pid' => '21.11157/epmy8-2k126', 'pidType' => 'Handle', 'name' => 'Curated name'];
    $result = app(InstrumentNameResolutionService::class)->resolve([$selection]);
    expect($result[0])->toMatchArray($selection)
        ->and($result[0]['resolution']['status'])->toBe('identifier_conflict')
        ->and($result[0]['resolution']['identifiers'])->toHaveCount(2);
});

it('retains the previous snapshot when a replacement is malformed', function () {
    $previous = Storage::get(Pid4instVocabularyService::FILE);
    expect(fn () => app(Pid4instVocabularyService::class)->replace('{}'))->toThrow(RuntimeException::class);
    expect(Storage::get(Pid4instVocabularyService::FILE))->toBe($previous);
});

it('keeps unknown instruments with an empty name saveable and ignores malformed legacy session entries', function () {
    $resolver = app(InstrumentNameResolutionService::class);
    expect($resolver->resolve([]))->toBe([]);
    $resolved = $resolver->resolve([
        ['pid' => '21.test/unknown', 'pidType' => 'Handle', 'name' => ''],
        ['name' => 'Malformed legacy entry'],
    ]);
    expect($resolved)->toHaveCount(1)->and($resolved[0])->toBe([
        'pid' => '21.test/unknown', 'pidType' => 'Handle', 'name' => '21.test/unknown', 'resolution' => ['status' => 'not_found'],
    ]);
});

it('does not publish or invalidate the old snapshot when a temporary write fails', function () {
    $service = app(Pid4instVocabularyService::class);
    $before = $service->getLocalPayload();
    Storage::shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('delete')->once()->andReturn(true);
    expect(fn () => $service->replace(json_encode($before)))->toThrow(VocabularyReadException::class);
    expect($service->getLocalPayload())->toBe($before);
});
