<?php

declare(strict_types=1);

use App\Services\B2inst\B2instInstrumentNormalizer;
use App\Services\B2inst\B2instPidResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\B2instFixture as Fixture;

covers(B2instInstrumentNormalizer::class, B2instPidResolver::class);

beforeEach(function (): void {
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0]);
    Cache::flush();
    Http::preventStrayRequests();
});

it('normalizes matching provider Handles and retains the public field contract', function (): void {
    Http::fake();
    $result = app(B2instInstrumentNormalizer::class)->normalize(Fixture::record());
    expect($result)->toBe([
        'id' => 'abc12-def34', 'pid' => '21.T11975/abc12-def34', 'pidType' => 'Handle',
        'name' => 'Test instrument abc12-def34', 'description' => 'Instrument description',
        'landingPage' => 'https://instruments.example.test/abc12-def34', 'owners' => ['GFZ'],
        'manufacturers' => ['Acme'], 'model' => 'M-1', 'instrumentTypes' => ['Sensor'], 'measuredVariables' => ['Temperature'],
    ]);
    Http::assertNothingSent();
});

it('resolves the observed migrated GFZ record to its registered provider PID', function (): void {
    config(['b2inst.host' => 'https://b2inst.pid.gwdg.de']);
    $record = Fixture::migrated();
    Http::fake([
        'https://hdl.handle.net/api/handles/21.11157/7z780-7kj63' => Http::response(['responseCode' => 100], 404),
        'https://hdl.handle.net/api/handles/21.11157/d96a9d31-fd99-4a0c-9b98-514a04b18423' => Http::response(Fixture::handle('https://b2inst.gwdg.de/records/d96a9d31fd994a0c9b98514a04b18423')),
        'https://b2inst.gwdg.de/api/records/d96a9d31fd994a0c9b98514a04b18423' => Http::response($record),
    ]);
    $normalizer = app(B2instInstrumentNormalizer::class);
    expect($normalizer->normalize($record)['pid'])->toBe('21.11157/d96a9d31-fd99-4a0c-9b98-514a04b18423')
        ->and($normalizer->provenance($record)['metadataIdentifier']['identifierValue'])->toBe('21.11157/7z780-7kj63');
    Http::assertSentCount(3);
    $normalizer->normalize($record);
    Http::assertSentCount(4); // Handle results cached, record-family evidence rechecked.
});

it('accepts metadata-only Handles only with verified identity evidence', function (): void {
    $record = Fixture::record();
    unset($record['pids']['epic']);
    Http::fake(['https://hdl.handle.net/*' => Http::response(Fixture::handle($record['metadata']['LandingPage']))]);
    expect(app(B2instInstrumentNormalizer::class)->normalize($record)['pid'])->toBe('21.T11975/abc12-def34');
    Http::assertSentCount(1); // Never fetches the external landing page.
});

it('distinguishes missing Handles from unavailable resolution', function (bool $unavailable): void {
    $record = Fixture::record();
    unset($record['pids']['epic']);
    Http::fake(['https://hdl.handle.net/*' => $unavailable ? Http::failedConnection() : Http::response(['responseCode' => 100], 404)]);
    expect(fn () => app(B2instInstrumentNormalizer::class)->normalize($record))
        ->toThrow($unavailable ? RuntimeException::class : InvalidArgumentException::class);
    Http::assertSentCount($unavailable ? 3 : 1);
})->with([true, false]);

it('rejects ambiguous or unrelated identities without requesting foreign targets', function (string $target): void {
    $record = Fixture::record(overrides: ['pids' => ['epic' => ['identifier' => '21.T11975/other']]]);
    Http::fake(['https://hdl.handle.net/*' => Http::response(Fixture::handle($target))]);
    expect(fn () => app(B2instInstrumentNormalizer::class)->normalize($record))->toThrow(RuntimeException::class, 'Conflicting');
    Http::assertSentCount(2);
})->with(['https://evil.test/records/abc12-def34', 'https://b2inst.example.test/api/user/records/abc12-def34']);

it('accepts another version only when its parent establishes the same family', function (): void {
    $record = Fixture::record(overrides: ['pids' => ['epic' => ['identifier' => '21.T11975/other']]]);
    Http::fake([
        'https://hdl.handle.net/*' => Http::response(Fixture::handle('https://b2inst.example.test/records/old')),
        'https://b2inst.example.test/api/records/old' => Http::response(['id' => 'old', 'parent' => $record['parent']]),
    ]);
    expect(app(B2instInstrumentNormalizer::class)->normalize($record)['pid'])->toBe('21.T11975/other');
});

it('keeps DOI and URL identifiers independent of other provider PIDs', function (string $type, string $pid, string $expected): void {
    Http::fake();
    $record = Fixture::record(overrides: ['metadata' => ['Identifier' => ['identifierType' => $type, 'identifierValue' => $pid]]]);
    $result = app(B2instInstrumentNormalizer::class)->normalize($record);
    expect($result['pid'])->toBe($expected)->and($result['pidType'])->toBe($type);
    Http::assertNothingSent();
})->with([
    ['DOI', 'https://doi.org/10.1234/instrument', '10.1234/instrument'],
    ['URL', 'https://example.org/instrument', 'https://example.org/instrument'],
]);

it('excludes unsupported types, invalid fields and non-current or invisible records', function (string $field, mixed $value): void {
    Http::fake();
    $record = Fixture::record();
    data_set($record, $field, $value);
    expect(fn () => app(B2instInstrumentNormalizer::class)->normalize($record))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['metadata.Identifier.identifierType', 'ARK'], ['metadata.Identifier.identifierValue', 'abc12-def34'],
    ['metadata.Name', []], ['metadata.Name', ' '], ['metadata.Owner', 'GFZ'],
    ['metadata.Manufacturer', [42]], ['metadata.InstrumentType', ['invalid']], ['metadata.MeasuredVariable', [7]],
    ['metadata.Model', 'model'], ['metadata.Description', []], ['metadata.LandingPage', []],
    ['metadata.SchemaVersion', '2.0'], ['metadata.SchemaVersion', null],
    ['access.record', 'restricted'], ['is_published', false], ['versions.is_latest', false], ['deletion_status.is_deleted', true],
]);

it('handles optional metadata without inventing landing pages', function (): void {
    $record = Fixture::record();
    foreach (['Model', 'LandingPage', 'Description', 'Owner', 'Manufacturer', 'MeasuredVariable', 'InstrumentType'] as $key) {
        unset($record['metadata'][$key]);
    }
    $result = app(B2instInstrumentNormalizer::class)->normalize($record);
    expect($result['model'])->toBeNull()->and($result['landingPage'])->toBe('')->and($result['owners'])->toBe([]);
});

it('normalizes supported Handle representations without synthesizing a PID', function (string $value): void {
    expect(B2instPidResolver::handle($value))->toBe('21.T11975/abc12-def34');
})->with(['21.T11975/abc12-def34', 'http://hdl.handle.net/21.T11975/abc12-def34', 'https://hdl.handle.net/21.T11975/abc12-def34?noredirect', 'https://hdl.handle.net/api/handles/21.T11975/abc12-def34']);

it('rejects uncertain resolver responses instead of treating them as unregistered', function (array $response, int $status): void {
    $record = Fixture::record();
    unset($record['pids']['epic']);
    Http::fake(['https://hdl.handle.net/*' => Http::response($response, $status)]);
    expect(fn () => app(B2instInstrumentNormalizer::class)->normalize($record))->toThrow(RuntimeException::class);
})->with([
    [['responseCode' => 2], 200], [['responseCode' => 1, 'values' => []], 200],
    [['responseCode' => 1, 'values' => [['type' => 'URL', 'data' => ['value' => 'invalid']]]], 200],
    [['responseCode' => 1, 'values' => []], 404],
]);

it('uses the sole registered metadata candidate when the conflicting provider is absent from Handle', function (): void {
    $record = Fixture::record(overrides: ['pids' => ['epic' => ['identifier' => '21.T11975/other']]]);
    Http::fake([
        'https://hdl.handle.net/api/handles/21.T11975/abc12-def34' => Http::response(Fixture::handle($record['metadata']['LandingPage'])),
        'https://hdl.handle.net/api/handles/21.T11975/other' => Http::response(['responseCode' => 100], 404),
    ]);
    expect(app(B2instInstrumentNormalizer::class)->normalize($record)['pid'])->toBe('21.T11975/abc12-def34');
});
