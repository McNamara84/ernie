<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\RaidStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raid.datacite_endpoint' => 'https://api.datacite.example.test',
        'raid.search_query' => 'identifiers.identifier:*raid.org.au*',
    ]);
    Storage::fake();
});

function createRaidPidSettingForStatus(): PidSetting
{
    return PidSetting::firstOrCreate(
        ['type' => PidSetting::TYPE_RAID],
        [
            'display_name' => 'RAiD (Research Activity Identifier)',
            'is_active' => true,
            'is_elmo_active' => true,
        ]
    );
}

describe('getLocalStatus', function () {
    test('returns not exists when RAiD file is missing', function () {
        $setting = createRaidPidSettingForStatus();

        $status = (new RaidStatusService)->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => false,
            'itemCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('reads wrapped RAiD file with total field', function () {
        Storage::put('raid/raid-projects.json', json_encode([
            'lastUpdated' => '2026-06-26T10:00:00Z',
            'total' => 2,
            'data' => [
                ['raidId' => 'https://raid.org/10.1234/alpha'],
            ],
        ], JSON_THROW_ON_ERROR));

        $setting = createRaidPidSettingForStatus();

        $status = (new RaidStatusService)->getLocalStatus($setting);

        expect($status)->toBe([
            'exists' => true,
            'itemCount' => 2,
            'lastUpdated' => '2026-06-26T10:00:00Z',
        ]);
    });

    test('falls back to data array count when total is missing', function () {
        Storage::put('raid/raid-projects.json', json_encode([
            'data' => [
                ['raidId' => 'https://raid.org/10.1234/alpha'],
                ['raidId' => 'https://raid.org/10.1234/beta'],
            ],
        ], JSON_THROW_ON_ERROR));

        $setting = createRaidPidSettingForStatus();

        $status = (new RaidStatusService)->getLocalStatus($setting);

        expect($status['itemCount'])->toBe(2);
    });
});

describe('getRemoteCount', function () {
    test('returns RAiD count from DataCite search metadata', function () {
        Http::fake([
            'api.datacite.example.test/dois*' => Http::response([
                'meta' => [
                    'total' => 570,
                    'totalPages' => 570,
                    'page' => 1,
                ],
                'data' => [],
            ], 200),
        ]);

        $count = (new RaidStatusService)->getRemoteCount();

        expect($count)->toBe(570);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.datacite.example.test/dois?')
                && ($query['query'] ?? null) === 'identifiers.identifier:*raid.org.au*'
                && (string) ($query['page']['size'] ?? '') === '1';
        });
    });

    test('throws exception on DataCite failure', function () {
        Http::fake([
            'api.datacite.example.test/dois*' => Http::response('Service unavailable', 503),
        ]);

        expect(fn () => (new RaidStatusService)->getRemoteCount())
            ->toThrow(RuntimeException::class, 'Failed to fetch from DataCite RAiD search: HTTP 503');
    });
});

describe('compareWithRemote', function () {
    test('identifies update availability when remote count differs', function () {
        Storage::put('raid/raid-projects.json', json_encode([
            'lastUpdated' => '2026-06-25T00:00:00Z',
            'total' => 10,
            'data' => [],
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'api.datacite.example.test/dois*' => Http::response([
                'meta' => ['total' => 12],
                'data' => [],
            ], 200),
        ]);

        $result = (new RaidStatusService)->compareWithRemote(createRaidPidSettingForStatus());

        expect($result)->toBe([
            'localCount' => 10,
            'remoteCount' => 12,
            'updateAvailable' => true,
            'lastUpdated' => '2026-06-25T00:00:00Z',
        ]);
    });
});
