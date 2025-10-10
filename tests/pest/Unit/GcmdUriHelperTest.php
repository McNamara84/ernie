<?php

use App\Support\GcmdUriHelper;

describe('GcmdUriHelper', function () {
    it('extracts UUID from new GCMD URI format', function () {
        $uri = 'https://gcmd.earthdata.nasa.gov/kms/concept/a7558f90-6c61-4673-8d66-6185c0654cd1';
        $uuid = GcmdUriHelper::extractUuid($uri);
        
        expect($uuid)->toBe('a7558f90-6c61-4673-8d66-6185c0654cd1');
    });

    it('extracts UUID from old GCMD URI format', function () {
        $uri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a7558f90-6c61-4673-8d66-6185c0654cd1';
        $uuid = GcmdUriHelper::extractUuid($uri);
        
        expect($uuid)->toBe('a7558f90-6c61-4673-8d66-6185c0654cd1');
    });

    it('returns null for URI without UUID', function () {
        $uri = 'https://gcmd.earthdata.nasa.gov/kms/concept/';
        $uuid = GcmdUriHelper::extractUuid($uri);
        
        expect($uuid)->toBeNull();
    });

    it('returns null for null input', function () {
        $uuid = GcmdUriHelper::extractUuid(null);
        
        expect($uuid)->toBeNull();
    });

    it('returns null for empty string', function () {
        $uuid = GcmdUriHelper::extractUuid('');
        
        expect($uuid)->toBeNull();
    });

    it('returns null for invalid UUID format', function () {
        $uri = 'https://gcmd.earthdata.nasa.gov/kms/concept/not-a-valid-uuid';
        $uuid = GcmdUriHelper::extractUuid($uri);
        
        expect($uuid)->toBeNull();
    });

    it('builds correct concept URI from UUID', function () {
        $uuid = 'a7558f90-6c61-4673-8d66-6185c0654cd1';
        $uri = GcmdUriHelper::buildConceptUri($uuid);
        
        expect($uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/a7558f90-6c61-4673-8d66-6185c0654cd1');
    });

    it('converts old URI to new format', function () {
        $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a7558f90-6c61-4673-8d66-6185c0654cd1';
        $newUri = GcmdUriHelper::convertToNewUri($oldUri);
        
        expect($newUri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/a7558f90-6c61-4673-8d66-6185c0654cd1');
    });

    it('returns null when converting invalid old URI', function () {
        $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/invalid';
        $newUri = GcmdUriHelper::convertToNewUri($oldUri);
        
        expect($newUri)->toBeNull();
    });
});
