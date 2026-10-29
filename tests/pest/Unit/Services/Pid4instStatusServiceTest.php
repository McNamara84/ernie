<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\Pid4instStatusService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(Pid4instStatusService::class);

function pid4instStatusHost(): string
{
    return rtrim((string) config('b2inst.host'), '/');
}

describe('Pid4instStatusService', function () {
    beforeEach(function () {
        config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0]);
        Http::preventStrayRequests();
        $this->service = new Pid4instStatusService;
    });

    describe('getLocalStatus', function () {
        it('returns not-exists status when file does not exist', function () {
            Storage::fake('local');

            $setting = new PidSetting;
            $setting->type = PidSetting::TYPE_PID4INST;

            $result = $this->service->getLocalStatus($setting);

            expect($result['exists'])->toBeFalse();
            expect($result['itemCount'])->toBe(0);
            expect($result['lastUpdated'])->toBeNull();
        });

        it('returns not-exists status for empty file', function () {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', '');

            $setting = new PidSetting;
            $setting->type = PidSetting::TYPE_PID4INST;

            $result = $this->service->getLocalStatus($setting);

            expect($result['exists'])->toBeFalse();
        });

        it('returns not-exists status for invalid JSON', function () {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', 'not-json');

            $setting = new PidSetting;
            $setting->type = PidSetting::TYPE_PID4INST;

            $result = $this->service->getLocalStatus($setting);

            expect($result['exists'])->toBeFalse();
        });

        it('returns status with total count from file', function () {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', json_encode([
                'lastUpdated' => '2025-01-15T10:00:00Z',
                'total' => 150,
                'data' => [],
            ]));

            $setting = new PidSetting;
            $setting->type = PidSetting::TYPE_PID4INST;

            $result = $this->service->getLocalStatus($setting);

            expect($result['exists'])->toBeTrue();
            expect($result['itemCount'])->toBe(150);
            expect($result['lastUpdated'])->toBe('2025-01-15T10:00:00Z');
        });

        it('counts data array when total is missing', function () {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', json_encode([
                'lastUpdated' => '2025-01-15T10:00:00Z',
                'data' => [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ],
            ]));

            $setting = new PidSetting;
            $setting->type = PidSetting::TYPE_PID4INST;

            $result = $this->service->getLocalStatus($setting);

            expect($result['exists'])->toBeTrue();
            expect($result['itemCount'])->toBe(3);
        });
    });

    describe('compareWithRemote', function () {
        it('compares the synchronized server count while displaying accepted instruments', function (int $remoteCount, bool $updateAvailable) {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', json_encode([
                'lastUpdated' => '2026-10-29T05:57:58Z',
                'total' => 812,
                'data' => [],
                '_sync' => ['serverCount' => 815],
            ]));
            Http::fake(['*' => Http::response(['hits' => ['total' => $remoteCount, 'hits' => []]])]);
            $setting = new PidSetting(['type' => PidSetting::TYPE_PID4INST]);

            expect($this->service->compareWithRemote($setting))->toBe([
                'localCount' => 812,
                'remoteCount' => $remoteCount,
                'updateAvailable' => $updateAvailable,
                'lastUpdated' => '2026-10-29T05:57:58Z',
                'comparisonBasis' => 'count',
            ]);
        })->with([[815, false], [816, true], [814, true], [812, true]]);

        it('falls back to the offered count for legacy or invalid synchronization metadata', function (array $sync, int $remoteCount, bool $updateAvailable) {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', json_encode(['total' => 812, 'data' => [], ...$sync]));
            Http::fake(['*' => Http::response(['hits' => ['total' => $remoteCount, 'hits' => []]])]);
            $setting = new PidSetting(['type' => PidSetting::TYPE_PID4INST]);

            $result = $this->service->compareWithRemote($setting);
            expect($result['localCount'])->toBe(812)->and($result['updateAvailable'])->toBe($updateAvailable);
        })->with([
            [[], 812, false], [[], 815, true],
            [['_sync' => []], 812, false],
            [['_sync' => ['serverCount' => null]], 812, false],
            [['_sync' => ['serverCount' => -1]], 812, false],
            [['_sync' => ['serverCount' => '815']], 812, false],
        ]);

        it('recognizes a zero server baseline for an empty synchronized snapshot', function () {
            Storage::fake('local');
            Storage::put('pid4inst-instruments.json', json_encode(['data' => [], '_sync' => ['serverCount' => 0]]));
            Http::fake(['*' => Http::response(['hits' => ['total' => 0, 'hits' => []]])]);
            $setting = new PidSetting(['type' => PidSetting::TYPE_PID4INST]);

            expect($this->service->getLocalStatus($setting)['serverCount'])->toBe(0)
                ->and($this->service->compareWithRemote($setting)['updateAvailable'])->toBeFalse();
        });
    });

    describe('getRemoteCount', function () {
        it('returns total hits from b2inst API', function () {
            Http::fake([
                pid4instStatusHost().'/api/records*' => Http::response([
                    'hits' => ['total' => 500, 'hits' => []],
                ]),
            ]);

            $result = $this->service->getRemoteCount();

            expect($result)->toBe(500);

            Http::assertSentCount(1);

            Http::assertSent(function (Request $request): bool {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return $request->method() === 'GET'
                    && str_starts_with($request->url(), pid4instStatusHost().'/api/records?')
                    && ($query['size'] ?? null) === '1'
                    && ($query['page'] ?? null) === '1'
                    && ($query['sort'] ?? null) === 'newest'
                    && ($query['allversions'] ?? null) === 'false';
            });
        });

        it('throws RuntimeException on API failure', function () {
            Http::fake([
                pid4instStatusHost().'/api/records*' => Http::response('Server Error', 500),
            ]);

            $this->service->getRemoteCount();
        })->throws(RuntimeException::class, 'Failed to fetch from b2inst API');
    });
});
