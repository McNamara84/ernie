<?php

declare(strict_types=1);

use App\Models\Right;
use App\Services\Spdx\SpdxLicenseData;
use App\Services\Spdx\SpdxLicenseLookup;

covers(SpdxLicenseData::class, SpdxLicenseLookup::class);

it('builds the catalog from active SPDX rights only', function () {
    Right::factory()->ccBy4()->create();
    Right::factory()->create([
        'identifier' => 'CUSTOM-1',
        'name' => 'Custom Institutional License',
        'uri' => 'https://example.test/licenses/custom-1',
        'scheme_uri' => null,
        'is_active' => true,
    ]);
    Right::factory()->create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License 2.0',
        'uri' => 'https://spdx.org/licenses/Apache-2.0.html',
        'scheme_uri' => SpdxLicenseLookup::SCHEME_URI,
        'is_active' => false,
    ]);

    $lookup = SpdxLicenseLookup::fromRightsCatalog();

    expect($lookup->findByIdentifier('CC-BY-4.0')?->name)
        ->toBe('Creative Commons Attribution 4.0 International')
        ->and($lookup->findByIdentifier('CUSTOM-1'))->toBeNull()
        ->and($lookup->findByIdentifier('Apache-2.0'))->toBeNull();
});

it('copies stable SPDX catalog fields from a Right model', function () {
    $right = Right::factory()->ccBy4()->create();

    $data = SpdxLicenseData::fromRight($right);

    expect($data->identifier)->toBe('CC-BY-4.0')
        ->and($data->name)->toBe('Creative Commons Attribution 4.0 International')
        ->and($data->rightsUri)->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($data->schemeUri)->toBe(SpdxLicenseLookup::SCHEME_URI);
});
