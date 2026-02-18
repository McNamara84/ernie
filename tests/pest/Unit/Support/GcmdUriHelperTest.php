<?php

declare(strict_types=1);

use App\Support\GcmdUriHelper;

covers(GcmdUriHelper::class);

describe('GcmdUriHelper::extractUuid()', function () {
    it('extracts UUID from new format GCMD URI', function () {
        $uri = 'https://gcmd.earthdata.nasa.gov/kms/concept/a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        expect(GcmdUriHelper::extractUuid($uri))
            ->toBe('a1b2c3d4-e5f6-7890-abcd-ef1234567890');
    });

    it('extracts UUID from old format GCMD URI', function () {
        $uri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        expect(GcmdUriHelper::extractUuid($uri))
            ->toBe('a1b2c3d4-e5f6-7890-abcd-ef1234567890');
    });

    it('returns null for null input')
        ->expect(fn () => GcmdUriHelper::extractUuid(null))
        ->toBeNull();

    it('returns null for empty string')
        ->expect(fn () => GcmdUriHelper::extractUuid(''))
        ->toBeNull();

    it('returns null for URI without UUID', function () {
        expect(GcmdUriHelper::extractUuid('https://example.com/no-uuid-here'))->toBeNull();
    });

    it('handles case-insensitive UUIDs', function () {
        $uri = 'https://gcmd.earthdata.nasa.gov/kms/concept/A1B2C3D4-E5F6-7890-ABCD-EF1234567890';

        expect(GcmdUriHelper::extractUuid($uri))
            ->toBe('A1B2C3D4-E5F6-7890-ABCD-EF1234567890');
    });
});

describe('GcmdUriHelper::buildConceptUri()', function () {
    it('builds new format GCMD URI from UUID', function () {
        expect(GcmdUriHelper::buildConceptUri('a1b2c3d4-e5f6-7890-abcd-ef1234567890'))
            ->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/a1b2c3d4-e5f6-7890-abcd-ef1234567890');
    });
});

describe('GcmdUriHelper::convertToNewUri()', function () {
    it('converts old GCMD URI to new format', function () {
        $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $expected = 'https://gcmd.earthdata.nasa.gov/kms/concept/a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        expect(GcmdUriHelper::convertToNewUri($oldUri))->toBe($expected);
    });

    it('returns same format when already new URI', function () {
        $newUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        expect(GcmdUriHelper::convertToNewUri($newUri))->toBe($newUri);
    });

    it('returns null for null input')
        ->expect(fn () => GcmdUriHelper::convertToNewUri(null))
        ->toBeNull();

    it('returns null for invalid URI')
        ->expect(fn () => GcmdUriHelper::convertToNewUri('not-a-gcmd-uri'))
        ->toBeNull();
});
