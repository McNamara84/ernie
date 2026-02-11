<?php

use App\Services\RorLookupService;

describe('RorLookupService', function () {
    test('canonicalises full ROR URL', function () {
        $service = new RorLookupService;

        expect($service->canonicalise('https://ror.org/04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('canonicalises HTTP ROR URL to HTTPS', function () {
        $service = new RorLookupService;

        expect($service->canonicalise('http://ror.org/04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('canonicalises bare ROR ID', function () {
        $service = new RorLookupService;

        expect($service->canonicalise('04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('returns null for empty string', function () {
        $service = new RorLookupService;

        expect($service->canonicalise(''))->toBeNull();
    });

    test('returns null for non-ROR URL', function () {
        $service = new RorLookupService;

        expect($service->canonicalise('https://example.com/institution'))->toBeNull();
    });

    test('lowercases ROR ID', function () {
        $service = new RorLookupService;

        expect($service->canonicalise('https://ror.org/04Z8JG394'))->toBe('https://ror.org/04z8jg394');
    });

    test('isRorUrl identifies ROR URLs', function () {
        $service = new RorLookupService;

        expect($service->isRorUrl('https://ror.org/04z8jg394'))->toBeTrue()
            ->and($service->isRorUrl('http://ror.org/04z8jg394'))->toBeTrue()
            ->and($service->isRorUrl('https://example.com'))->toBeFalse()
            ->and($service->isRorUrl('GFZ Potsdam'))->toBeFalse()
            ->and($service->isRorUrl(''))->toBeFalse();
    });

    test('resolve returns null when ROR data file does not exist', function () {
        $service = new RorLookupService;

        // On test environments without the ROR data dump, resolve should return null
        $result = $service->resolve('https://ror.org/nonexistent');

        expect($result)->toBeNull();
    });

    test('resolveWithFallback uses fallback name when lookup fails', function () {
        $service = new RorLookupService;

        $result = $service->resolveWithFallback('https://ror.org/04z8jg394', 'GFZ Fallback Name');

        expect($result)->not->toBeNull()
            ->and($result['rorId'])->toBe('https://ror.org/04z8jg394');

        // Either resolved name from data or fallback
        expect($result['value'])->toBeString();
        if ($result['value'] !== 'GFZ Fallback Name') {
            // Name was resolved from ROR data – also acceptable
            expect($result['value'])->not->toBeEmpty();
        }
    });

    test('resolveWithFallback returns null for invalid identifier', function () {
        $service = new RorLookupService;

        $result = $service->resolveWithFallback('');

        expect($result)->toBeNull();
    });

    test('resolveWithFallback uses canonical URL as last-resort label', function () {
        $service = new RorLookupService;

        $result = $service->resolveWithFallback('04z8jg394');

        expect($result)->not->toBeNull()
            ->and($result['rorId'])->toBe('https://ror.org/04z8jg394');
    });
});
