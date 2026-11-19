<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\Pid4instStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\B2instFixture;
use Tests\Fixtures\DataCiteInstrumentFixture;

covers(Pid4instStatusService::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    Http::preventStrayRequests();
    config(['b2inst.host' => 'https://b2inst.example.test', 'pidinst.datacite.host' => 'https://datacite.example.test']);
});

it('compares each raw count without confusing overlap with changes', function (int $b2, int $dc, bool $expected): void {
    Storage::put('pid4inst-instruments.json', json_encode(['total' => 11, 'data' => [], '_sync' => ['sources' => [
        'b2inst' => ['serverCount' => 9], 'datacite' => ['serverCount' => 6],
    ]]]));
    Http::fake([
        'https://b2inst.example.test/*' => Http::response(B2instFixture::page([], $b2)),
        'https://datacite.example.test/*' => Http::response(DataCiteInstrumentFixture::page([], $dc)),
    ]);
    $result = app(Pid4instStatusService::class)->compareWithRemote(new PidSetting(['type' => 'pid4inst']));
    expect($result['updateAvailable'])->toBe($expected)->and($result['localCount'])->toBe(11)
        ->and($result['remoteCount'])->toBe($b2 + $dc)
        ->and($result['sources']['datacite'])->toBe(['syncedCount' => 6, 'remoteCount' => $dc]);
})->with([[9, 6, false], [10, 5, true], [8, 7, true], [9, 7, true], [10, 6, true]]);

it('does not report a successful comparison when either count request fails', function (string $source): void {
    Http::fake([
        'https://b2inst.example.test/*' => $source === 'b2inst' ? Http::response([], 400) : Http::response(B2instFixture::page([])),
        'https://datacite.example.test/*' => $source === 'datacite' ? Http::response([], 400) : Http::response(DataCiteInstrumentFixture::page()),
    ]);
    expect(fn () => app(Pid4instStatusService::class)->compareWithRemote(new PidSetting(['type' => 'pid4inst'])))->toThrow(RuntimeException::class);
})->with(['b2inst', 'datacite']);
