<?php

declare(strict_types=1);

use App\Services\Spdx\SpdxLicenseData;
use App\Services\Spdx\SpdxLicenseLookup;
use App\Services\Spdx\SpdxRightsMatcher;
use App\Services\Spdx\SpdxRightsMatchInput;

covers(SpdxRightsMatcher::class);

function spdxRightsMatcherTestLookup(): SpdxLicenseLookup
{
    return SpdxLicenseLookup::fromLicenses([
        new SpdxLicenseData(
            identifier: 'CC-BY-4.0',
            name: 'Creative Commons Attribution 4.0 International',
            rightsUri: 'https://creativecommons.org/licenses/by/4.0/',
            schemeUri: SpdxLicenseLookup::SCHEME_URI,
        ),
        new SpdxLicenseData(
            identifier: 'Apache-2.0',
            name: 'Apache License 2.0',
            rightsUri: 'https://www.apache.org/licenses/LICENSE-2.0',
            schemeUri: SpdxLicenseLookup::SCHEME_URI,
        ),
    ]);
}

it('matches an exact SPDX identifier', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 10,
            rightsIdentifier: 'CC-BY-4.0',
            rightsIdentifierScheme: 'SPDX',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeTrue()
        ->and($result->license?->identifier)->toBe('CC-BY-4.0')
        ->and($result->matchType)->toBe('resource_rights.rights_identifier')
        ->and($result->score)->toBe(1.0);
});

it('matches a reviewed alias when only rights text was imported', function () {
    $input = new SpdxRightsMatchInput(
        resourceId: 1,
        targetType: 'resource_right',
        targetId: 11,
        rightsText: 'CC BY 4.0',
    );

    $result = (new SpdxRightsMatcher)->match(
        input: $input,
        lookup: spdxRightsMatcherTestLookup(),
    );

    $metadata = $result->toSuggestionMetadata($input);

    expect($result->isMatched())->toBeTrue()
        ->and($result->license?->identifier)->toBe('CC-BY-4.0')
        ->and($result->matchType)->toBe('resource_rights.rights_text_alias')
        ->and($metadata['current'])->toBe(['rights' => 'CC BY 4.0'])
        ->and($metadata['proposed']['rights'])->toBe('Creative Commons Attribution 4.0 International')
        ->and($metadata['proposed']['rights_uri'])->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($metadata['proposed']['rights_identifier'])->toBe('CC-BY-4.0')
        ->and($metadata['proposed']['rights_identifier_scheme'])->toBe('SPDX')
        ->and($metadata['proposed']['scheme_uri'])->toBe('https://spdx.org/licenses/');
});

it('marks custom or non-SPDX rights as unsupported', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 12,
            rightsText: 'Use requires an individual license agreement with the data provider.',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeFalse()
        ->and($result->status)->toBe('unsupported')
        ->and($result->license)->toBeNull();
});
