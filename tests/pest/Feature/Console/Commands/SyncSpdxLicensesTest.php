<?php

declare(strict_types=1);

use App\Console\Commands\SyncSpdxLicenses;
use App\Models\Right;
use Illuminate\Support\Facades\Http;

covers(SyncSpdxLicenses::class);

describe('spdx:sync-licenses', function () {
    it('syncs licenses from SPDX API', function () {
        Http::fake([
            'spdx.org/licenses/licenses.json' => Http::response([
                'licenses' => [
                    [
                        'licenseId' => 'MIT',
                        'name' => 'MIT License',
                        'reference' => 'https://spdx.org/licenses/MIT.html',
                    ],
                    [
                        'licenseId' => 'Apache-2.0',
                        'name' => 'Apache License 2.0',
                        'reference' => 'https://spdx.org/licenses/Apache-2.0.html',
                    ],
                ],
            ]),
        ]);

        $this->artisan('spdx:sync-licenses')
            ->expectsOutput('SPDX licenses synced: 2')
            ->assertExitCode(0);

        expect(Right::where('identifier', 'MIT')->exists())->toBeTrue()
            ->and(Right::where('identifier', 'Apache-2.0')->exists())->toBeTrue();

        $mit = Right::where('identifier', 'MIT')->first();
        expect($mit->name)->toBe('MIT License')
            ->and($mit->uri)->toBe('https://spdx.org/licenses/MIT.html')
            ->and($mit->scheme_uri)->toBe('https://spdx.org/licenses/');
    });

    it('updates existing licenses without duplicating', function () {
        Http::fake([
            'spdx.org/licenses/licenses.json' => Http::response([
                'licenses' => [
                    ['licenseId' => 'MIT', 'name' => 'MIT License', 'reference' => 'https://spdx.org/licenses/MIT.html'],
                ],
            ]),
        ]);

        // Create existing license
        Right::create(['identifier' => 'MIT', 'name' => 'Old MIT Name', 'uri' => null, 'scheme_uri' => null]);

        $this->artisan('spdx:sync-licenses')->assertExitCode(0);

        expect(Right::where('identifier', 'MIT')->count())->toBe(1);

        $mit = Right::where('identifier', 'MIT')->first();
        expect($mit->name)->toBe('MIT License');
    });

    it('handles HTTP failure gracefully', function () {
        Http::fake([
            'spdx.org/licenses/licenses.json' => Http::response('Server Error', 500),
        ]);

        $this->artisan('spdx:sync-licenses')
            ->expectsOutputToContain('Failed to fetch SPDX licenses')
            ->assertExitCode(1);
    });

    it('handles connection exception gracefully', function () {
        Http::fake([
            'spdx.org/licenses/licenses.json' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Timeout'),
        ]);

        $this->artisan('spdx:sync-licenses')
            ->expectsOutputToContain('Failed to fetch SPDX licenses')
            ->assertExitCode(1);
    });

    it('handles empty license list', function () {
        Http::fake([
            'spdx.org/licenses/licenses.json' => Http::response(['licenses' => []]),
        ]);

        $this->artisan('spdx:sync-licenses')
            ->expectsOutput('SPDX licenses synced: 0')
            ->assertExitCode(0);
    });
});
