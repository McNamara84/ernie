<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Services\Pid4instStatusService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(Pid4instStatusService::class);

describe('Pid4instStatusService', function () {
    beforeEach(function () {
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

    describe('getRemoteCount', function () {
        it('returns total hits from b2inst API', function () {
            Http::fake([
                '*' => Http::response([
                    'hits' => ['total' => 500],
                ]),
            ]);

            $result = $this->service->getRemoteCount();

            expect($result)->toBe(500);
        });

        it('throws RuntimeException on API failure', function () {
            Http::fake([
                '*' => Http::response('Server Error', 500),
            ]);

            $this->service->getRemoteCount();
        })->throws(\RuntimeException::class, 'Failed to fetch from b2inst API');
    });
});
