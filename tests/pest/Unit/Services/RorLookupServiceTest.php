<?php

declare(strict_types=1);

use App\Services\RorLookupService;
use Illuminate\Support\Facades\Storage;

covers(RorLookupService::class);

describe('canonicalise', function (): void {
    test('normalizes full URL', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise('https://ror.org/04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('normalizes HTTP URL to HTTPS', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise('http://ror.org/04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('normalizes bare ID', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise('04z8jg394'))->toBe('https://ror.org/04z8jg394');
    });

    test('returns null for empty string', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise(''))->toBeNull();
    });

    test('returns null for non-ROR URL', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise('https://example.com/04z8jg394'))->toBeNull();
    });

    test('lowercases ROR IDs', function (): void {
        $service = new RorLookupService;

        expect($service->canonicalise('https://ror.org/04Z8JG394'))->toBe('https://ror.org/04z8jg394');
    });
});

describe('isRorUrl', function (): void {
    test('detects valid ROR URLs', function (): void {
        $service = new RorLookupService;

        expect($service->isRorUrl('https://ror.org/04z8jg394'))->toBeTrue();
        expect($service->isRorUrl('http://ror.org/04z8jg394'))->toBeTrue();
        expect($service->isRorUrl('https://ror.org/04z8jg394/'))->toBeTrue();
    });

    test('rejects non-ROR URLs', function (): void {
        $service = new RorLookupService;

        expect($service->isRorUrl('https://example.com/04z8jg394'))->toBeFalse();
        expect($service->isRorUrl('04z8jg394'))->toBeFalse();
        expect($service->isRorUrl(''))->toBeFalse();
    });
});

describe('resolve', function (): void {
    test('resolves known ROR ID from file', function (): void {
        Storage::fake('local');
        Storage::disk('local')->put('ror/ror-affiliations.json', json_encode([
            'data' => [
                ['rorId' => 'https://ror.org/04z8jg394', 'prefLabel' => 'GFZ Helmholtz Centre'],
            ],
        ]));

        $service = new RorLookupService;

        expect($service->resolve('04z8jg394'))->toBe('GFZ Helmholtz Centre');
    });

    test('returns null for unknown ROR ID', function (): void {
        Storage::fake('local');
        Storage::disk('local')->put('ror/ror-affiliations.json', json_encode([
            'data' => [
                ['rorId' => 'https://ror.org/04z8jg394', 'prefLabel' => 'GFZ Helmholtz Centre'],
            ],
        ]));

        $service = new RorLookupService;

        expect($service->resolve('00000000'))->toBeNull();
    });

    test('returns null when file does not exist', function (): void {
        Storage::fake('local');

        $service = new RorLookupService;

        expect($service->resolve('04z8jg394'))->toBeNull();
    });
});

describe('resolveWithFallback', function (): void {
    test('returns resolved name and canonical URL', function (): void {
        Storage::fake('local');
        Storage::disk('local')->put('ror/ror-affiliations.json', json_encode([
            'data' => [
                ['rorId' => 'https://ror.org/04z8jg394', 'prefLabel' => 'GFZ Helmholtz Centre'],
            ],
        ]));

        $service = new RorLookupService;
        $result = $service->resolveWithFallback('04z8jg394', 'Fallback Name');

        expect($result)->toBe([
            'value' => 'GFZ Helmholtz Centre',
            'rorId' => 'https://ror.org/04z8jg394',
        ]);
    });

    test('uses fallback name when ROR lookup fails', function (): void {
        Storage::fake('local');
        Storage::disk('local')->put('ror/ror-affiliations.json', json_encode(['data' => []]));

        $service = new RorLookupService;
        $result = $service->resolveWithFallback('04z8jg394', 'Fallback Name');

        expect($result)->toBe([
            'value' => 'Fallback Name',
            'rorId' => 'https://ror.org/04z8jg394',
        ]);
    });

    test('returns null for invalid identifier', function (): void {
        $service = new RorLookupService;

        expect($service->resolveWithFallback(''))->toBeNull();
    });
});
