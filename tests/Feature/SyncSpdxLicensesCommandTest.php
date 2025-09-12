<?php

use App\Models\License;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs licenses from SPDX', function () {
    License::create(['identifier' => 'MIT', 'name' => 'Old MIT']);

    Http::fake([
        'https://spdx.org/licenses/licenses.json' => Http::response([
            'licenses' => [
                ['licenseId' => 'MIT', 'name' => 'MIT License'],
                ['licenseId' => 'Apache-2.0', 'name' => 'Apache License 2.0'],
            ],
        ]),
    ]);

    $this->artisan('spdx:sync-licenses')
        ->expectsOutput('SPDX licenses synced: 2')
        ->assertExitCode(0);

    expect(License::count())->toBe(2)
        ->and(License::where('identifier', 'MIT')->first()->name)
        ->toBe('MIT License')
        ->and(License::where('identifier', 'Apache-2.0')->exists())->toBeTrue();
});

