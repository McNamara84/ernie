<?php

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs licenses during database seeding', function () {
    Http::fake([
        'https://spdx.org/licenses/licenses.json' => Http::response([
            'licenses' => [
                ['licenseId' => 'MIT', 'name' => 'MIT License'],
            ],
        ]),
    ]);

    $this->seed();

    expect(License::where('identifier', 'MIT')->exists())->toBeTrue();
});
