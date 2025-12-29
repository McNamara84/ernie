<?php

declare(strict_types=1);

use App\Support\UrlNormalizer;

it('normalizes missing colon in app url scheme', function () {
    expect(UrlNormalizer::normalizeAppUrl('https//ernie.rz-vm182.gfz.de'))
        ->toBe('https://ernie.rz-vm182.gfz.de');

    expect(UrlNormalizer::normalizeAppUrl('http//example.test/foo'))
        ->toBe('http://example.test/foo');
});

it('collapses excessive slashes after scheme', function () {
    expect(UrlNormalizer::normalizeAppUrl('https:////example.test'))
        ->toBe('https://example.test');
});

it('returns null for empty inputs', function () {
    expect(UrlNormalizer::normalizeAppUrl(null))->toBeNull();
    expect(UrlNormalizer::normalizeAppUrl('   '))->toBeNull();
});
