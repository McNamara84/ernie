<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\InstrumentReferenceClassifierService;
use App\Services\Pid4instVocabularyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(InstrumentReferenceClassifierService::class);

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PIDINST', 'is_active' => true]);
});

it('recognizes explicit instrument DOIs and legacy Handles without a local registry', function (): void {
    expect(app(InstrumentReferenceClassifierService::class)->classify([
        ['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'Handle', 'relatedIdentifier' => '21.1234/sensor'],
        ['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.1234/sensor', 'resourceTypeGeneral' => 'Instrument'],
        ['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.1234/unknown'],
        ['relationType' => 'Cites', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.1234/sensor', 'resourceTypeGeneral' => 'Instrument'],
        ['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => 'invalid', 'resourceTypeGeneral' => 'Instrument'],
        ['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'URL', 'relatedIdentifier' => 'https://instruments.example.test'],
    ]))->toBe([true, true, false, false, false, false]);
    Http::assertNothingSent();
});

it('matches unmarked DOI references only to a unique active local record', function (string $condition, bool $expected): void {
    $record = ['id' => 'sensor', 'pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'Sensor',
        'lookupIdentifiers' => [['value' => 'https://doi.org/10.1234/SENSOR', 'type' => 'DOI', 'source' => 'metadata']]];
    $records = [$record];
    if ($condition === 'ambiguous') {
        $records[] = array_replace($record, ['id' => 'second']);
    }
    if ($condition === 'disabled') {
        PidSetting::where('type', 'pid4inst')->update(['is_active' => false]);
    }
    if ($condition === 'alias') {
        $records[0]['pid'] = '21.1234/sensor';
        $records[0]['pidType'] = 'Handle';
    }
    Storage::put(Pid4instVocabularyService::FILE, json_encode(['data' => $records]));
    $references = [['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => 'http://dx.doi.org/10.1234/SENSOR']];
    expect(app(InstrumentReferenceClassifierService::class)->classify($references))->toBe([$expected]);
    Http::assertNothingSent();
})->with([['unique', true], ['alias', true], ['ambiguous', false], ['disabled', false]]);

it('does not keep stale DOI classifications between imports', function (): void {
    $service = app(InstrumentReferenceClassifierService::class);
    $references = [['relationType' => 'IsCollectedBy', 'relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.1234/sensor']];
    expect($service->classify($references))->toBe([false]);
    app(Pid4instVocabularyService::class)->replace(json_encode(['data' => [['pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'Sensor']]]));
    expect($service->classify($references))->toBe([true]);
});
