<?php

declare(strict_types=1);

use App\Services\Spdx\SpdxLicenseData;
use App\Services\Spdx\SpdxLicenseLookup;
use App\Services\Spdx\SpdxRightsMatcher;
use App\Services\Spdx\SpdxRightsMatchInput;
use App\Services\Spdx\SpdxRightsMatchResult;

covers(
    SpdxLicenseData::class,
    SpdxLicenseLookup::class,
    SpdxRightsMatcher::class,
    SpdxRightsMatchInput::class,
    SpdxRightsMatchResult::class,
);

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
        ->and($metadata['proposed']['scheme_uri'])->toBe('https://spdx.org/licenses/')
        ->and($metadata['proposed'])->not->toHaveKey('language');
});

it('matches approved license URI aliases', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 12,
            rightsUri: 'http://creativecommons.org/licenses/by/4.0/legalcode',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeTrue()
        ->and($result->license?->identifier)->toBe('CC-BY-4.0')
        ->and($result->matchType)->toBe('resource_rights.rights_uri')
        ->and($result->score)->toBe(0.98);
});

it('matches canonical SPDX names exactly', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 13,
            rightsText: 'Apache License 2.0',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeTrue()
        ->and($result->license?->identifier)->toBe('Apache-2.0')
        ->and($result->matchType)->toBe('resource_rights.rights_text');
});

it('matches strict reviewed aliases inside longer text', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 14,
            rightsText: 'Dataset licensed as Apache License, Version 2.0 unless otherwise stated.',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeTrue()
        ->and($result->license?->identifier)->toBe('Apache-2.0')
        ->and($result->matchType)->toBe('resource_rights.rights_text_strict_variant')
        ->and($result->score)->toBe(0.90);
});

it('marks missing rights evidence as insufficient', function () {
    $input = new SpdxRightsMatchInput(
        resourceId: 1,
        targetType: 'resource_right',
        targetId: 15,
    );

    $result = (new SpdxRightsMatcher)->match(
        input: $input,
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($input->hasEvidence())->toBeFalse()
        ->and($input->currentPayload())->toBe([])
        ->and($result->status)->toBe('insufficient')
        ->and($result->isMatched())->toBeFalse();
});

it('marks custom or non-SPDX rights as unsupported', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 16,
            rightsText: 'Use requires an individual license agreement with the data provider.',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeFalse()
        ->and($result->status)->toBe('unsupported')
        ->and($result->license)->toBeNull();
});

it('does not accept identifiers from a non-SPDX scheme', function () {
    $result = (new SpdxRightsMatcher)->match(
        input: new SpdxRightsMatchInput(
            resourceId: 1,
            targetType: 'resource_right',
            targetId: 17,
            rightsIdentifier: 'CC-BY-4.0',
            rightsIdentifierScheme: 'LocalScheme',
        ),
        lookup: spdxRightsMatcherTestLookup(),
    );

    expect($result->isMatched())->toBeFalse()
        ->and($result->status)->toBe('unsupported')
        ->and($result->reason)->toBe('No strong SPDX identifier, URI, canonical name, or approved alias matched.');
});

it('omits empty optional proposed metadata fields', function () {
    $lookup = SpdxLicenseLookup::fromLicenses([
        new SpdxLicenseData(
            identifier: 'Custom-Test',
            name: 'Custom Test License',
            rightsUri: null,
            schemeUri: SpdxLicenseLookup::SCHEME_URI,
        ),
    ]);

    $input = new SpdxRightsMatchInput(
        resourceId: 1,
        targetType: 'resource_right',
        targetId: 13,
        rightsIdentifier: 'Custom-Test',
        rightsIdentifierScheme: 'SPDX',
    );

    $result = (new SpdxRightsMatcher)->match(
        input: $input,
        lookup: $lookup,
    );

    $metadata = $result->toSuggestionMetadata($input);

    expect($metadata['proposed'])
        ->toHaveKey('rights')
        ->toHaveKey('rights_identifier')
        ->not->toHaveKey('rights_uri')
        ->not->toHaveKey('language');
});

it('throws when non-matched results are converted into suggestion metadata', function () {
    $result = SpdxRightsMatchResult::unsupported('No useful match.');

    expect(fn () => $result->toSuggestionMetadata(new SpdxRightsMatchInput(
        resourceId: 1,
        targetType: 'resource_right',
        targetId: 18,
        rightsText: 'Custom',
    )))->toThrow(LogicException::class, 'Only matched SPDX rights can produce suggestion metadata.');
});
