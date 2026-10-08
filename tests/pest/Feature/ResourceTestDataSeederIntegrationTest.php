<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ResourceTestDataSeeder;
use Illuminate\Support\Facades\Http;

uses()->group('seeders', 'test-data');

it('creates every scenario after the complete application database seeder', function () {
    Http::fake([
        'https://spdx.org/licenses/licenses.json' => Http::response([
            'licenses' => [[
                'licenseId' => 'CC-BY-4.0',
                'name' => 'Creative Commons Attribution 4.0 International',
                'reference' => 'https://creativecommons.org/licenses/by/4.0/',
            ]],
        ]),
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(ResourceTestDataSeeder::class);

    expect(Resource::where('doi', 'LIKE', '10.5880/testdata.%')->count())->toBe(27)
        ->and(LandingPage::whereHas('resource', fn ($query) => $query->where('doi', 'LIKE', '10.5880/testdata.%'))
            ->where('is_published', true)->count())->toBe(27);
});
