<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\RorStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();
});

function createRorPidSetting(): PidSetting
{
    return PidSetting::firstOrCreate(
        ['type' => PidSetting::TYPE_ROR],
        [
            'display_name' => 'ROR (Research Organization Registry)',
            'is_active' => true,
            'is_elmo_active' => true,
        ]
    );
}

describe('getLocalStatus', function () {
    test('returns not exists when file is missing', function () {
        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => false,
            'itemCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('returns not exists when file is empty', function () {
        Storage::put('ror/ror-affiliations.json', '');

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => false,
            'itemCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('returns not exists when file contains invalid JSON', function () {
        Storage::put('ror/ror-affiliations.json', 'not valid json {{{');

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => false,
            'itemCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('reads wrapped format with total field', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [
                ['prefLabel' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394'],
                ['prefLabel' => 'MIT', 'rorId' => 'https://ror.org/042nb2s44'],
            ],
            'total' => 2,
        ]));

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => true,
            'itemCount' => 2,
            'lastUpdated' => '2025-06-01T10:00:00Z',
        ]);
    });

    test('uses total field over data array count when both exist', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [
                ['prefLabel' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394'],
            ],
            'total' => 105000,
        ]));

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status['itemCount'])->toBe(105000);
    });

    test('falls back to data array count when total is missing', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [
                ['prefLabel' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394'],
                ['prefLabel' => 'MIT', 'rorId' => 'https://ror.org/042nb2s44'],
                ['prefLabel' => 'ETH Zürich', 'rorId' => 'https://ror.org/05a28rw58'],
            ],
        ]));

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status['itemCount'])->toBe(3);
    });

    test('handles missing lastUpdated key gracefully', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'data' => [
                ['prefLabel' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394'],
            ],
            'total' => 1,
        ]));

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $status = $service->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => true,
            'itemCount' => 1,
            'lastUpdated' => null,
        ]);
    });
});

describe('getRemoteCount', function () {
    test('returns organization count from ROR API v2', function () {
        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response([
                'number_of_results' => 107542,
                'items' => [],
            ], 200),
        ]);

        $service = new RorStatusService;
        $count = $service->getRemoteCount();

        expect($count)->toBe(107542);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.ror.org/v2/organizations')
                && str_contains($request->url(), 'page=1');
        });
    });

    test('throws exception on API failure', function () {
        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response('Service Unavailable', 503),
        ]);

        $service = new RorStatusService;

        expect(fn () => $service->getRemoteCount())
            ->toThrow(RuntimeException::class, 'Failed to fetch from ROR API: HTTP 503');
    });

    test('throws exception on timeout', function () {
        Http::fake([
            'api.ror.org/v2/organizations*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        $service = new RorStatusService;

        expect(fn () => $service->getRemoteCount())
            ->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    });
});

describe('compareWithRemote', function () {
    test('identifies update available when remote has more organizations', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-01-01T00:00:00Z',
            'data' => [],
            'total' => 100000,
        ]));

        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response([
                'number_of_results' => 107542,
                'items' => [],
            ], 200),
        ]);

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $result = $service->compareWithRemote($setting);

        expect($result)->toBe([
            'localCount' => 100000,
            'remoteCount' => 107542,
            'updateAvailable' => true,
            'lastUpdated' => '2025-01-01T00:00:00Z',
        ]);
    });

    test('no update available when counts are equal', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [],
            'total' => 107542,
        ]));

        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response([
                'number_of_results' => 107542,
                'items' => [],
            ], 200),
        ]);

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $result = $service->compareWithRemote($setting);

        expect($result)->toBe([
            'localCount' => 107542,
            'remoteCount' => 107542,
            'updateAvailable' => false,
            'lastUpdated' => '2025-06-01T10:00:00Z',
        ]);
    });

    test('identifies update when remote has fewer organizations', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [],
            'total' => 108000,
        ]));

        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response([
                'number_of_results' => 107542,
                'items' => [],
            ], 200),
        ]);

        $setting = createRorPidSetting();

        $service = new RorStatusService;
        $result = $service->compareWithRemote($setting);

        expect($result['updateAvailable'])->toBeTrue();
    });
});
