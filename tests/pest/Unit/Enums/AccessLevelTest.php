<?php

declare(strict_types=1);

use App\Enums\AccessLevel;

covers(AccessLevel::class);

test('exposes canonical COAR metadata', function (AccessLevel $level, string $identifier, string $uri, bool $free): void {
    expect($level->coarIdentifier())->toBe($identifier)
        ->and($level->coarUri())->toBe($uri)
        ->and($level->isAccessibleForFree())->toBe($free)
        ->and(AccessLevel::coarScheme())->toBe('COAR Access Rights')
        ->and(AccessLevel::coarSchemeUri())->toBe('http://purl.org/coar/access_right/');
})->with([
    [AccessLevel::OPEN, 'c_abf2', 'http://purl.org/coar/access_right/c_abf2', true],
    [AccessLevel::RESTRICTED, 'c_16ec', 'http://purl.org/coar/access_right/c_16ec', false],
    [AccessLevel::EMBARGOED, 'c_f1cf', 'http://purl.org/coar/access_right/c_f1cf', false],
    [AccessLevel::METADATA_ONLY, 'c_14cb', 'http://purl.org/coar/access_right/c_14cb', false],
]);

test('accepts canonical and HTTPS COAR URIs', function (): void {
    expect(AccessLevel::fromCoarUri('http://purl.org/coar/access_right/c_abf2'))->toBe(AccessLevel::OPEN)
        ->and(AccessLevel::fromCoarUri(' HTTPS://purl.org/coar/access_right/c_16ec '))->toBe(AccessLevel::RESTRICTED)
        ->and(AccessLevel::fromCoarUri('https://example.org/not-coar'))->toBeNull()
        ->and(AccessLevel::fromCoarUri(null))->toBeNull();
});

test('maps only approved IGSN sample access aliases', function (string $value, ?AccessLevel $expected): void {
    expect(AccessLevel::fromSampleAccess($value))->toBe($expected);
})->with([
    ['Open Access', AccessLevel::OPEN],
    ['UNRESTRICTED', AccessLevel::OPEN],
    ['restricted_access', AccessLevel::RESTRICTED],
    ['limited', AccessLevel::RESTRICTED],
    ['embargoed-access', AccessLevel::EMBARGOED],
    ['metadata_only', AccessLevel::METADATA_ONLY],
    ['closed', null],
    ['by arrangement', null],
]);
