<?php

declare(strict_types=1);

use App\Services\LandingPageLicenseResolver;

covers(LandingPageLicenseResolver::class);

test('prefers the first valid SPDX license over earlier rights URIs', function () {
    $rightsList = [
        ['rights' => 'Custom', 'rightsUri' => 'https://example.org/custom-license'],
        [
            'rights' => 'CC BY 4.0',
            'rightsIdentifier' => 'CC-BY-4.0',
            'rightsIdentifierScheme' => 'SPDX',
            'schemeUri' => 'https://spdx.org/licenses/',
            'rightsUri' => 'https://creativecommons.org/licenses/by/4.0/',
        ],
    ];

    expect(app(LandingPageLicenseResolver::class)->resolve($rightsList))
        ->toBe('https://spdx.org/licenses/CC-BY-4.0');
});

test('falls back to the first valid rights URI in stable order', function () {
    $rightsList = [
        ['rights' => 'Unsafe', 'rightsUri' => "https://example.org/license\r\nX-Test: injected"],
        ['rights' => 'First valid', 'rightsUri' => 'https://example.org/license-a'],
        ['rights' => 'Second valid', 'rightsUri' => 'https://example.org/license-b'],
    ];

    expect(app(LandingPageLicenseResolver::class)->resolve($rightsList))
        ->toBe('https://example.org/license-a');
});

test('returns null when no safe HTTP license URI exists', function () {
    expect(app(LandingPageLicenseResolver::class)->resolve([
        ['rights' => 'Text only'],
        ['rights' => 'Local file', 'rightsUri' => 'file:///tmp/license'],
        ['rights' => 'Script', 'rightsUri' => 'javascript:alert(1)'],
    ]))->toBeNull();
});
