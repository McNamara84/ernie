<?php

declare(strict_types=1);

use App\Services\CrossrefFunderRor\CrossrefFunderRorIdentifierNormalizer;

it('normalizes supported Crossref Funder ID forms', function (string $input, bool $allowBareSuffix, string $normalized): void {
    $result = (new CrossrefFunderRorIdentifierNormalizer)->normalizeCrossrefFunderId($input, $allowBareSuffix);

    expect($result)->toBe([
        'identifier' => "https://doi.org/10.13039/{$normalized}",
        'normalized' => $normalized,
        'canonical' => "https://doi.org/10.13039/{$normalized}",
    ]);
})->with([
    'https DOI URL' => [' https://doi.org/10.13039/501100001659 ', false, '501100001659'],
    'dx DOI URL wrapped in angle brackets' => ['<http://dx.doi.org/10.13039/501100010956>', false, '501100010956'],
    'doi prefix' => ['doi:10.13039/501100000780', false, '501100000780'],
    'bare DOI body' => ['10.13039/501100004238', false, '501100004238'],
    'bare numeric suffix when allowed' => ['501100004189', true, '501100004189'],
]);

it('rejects unsupported Crossref Funder ID forms', function (?string $input, bool $allowBareSuffix): void {
    expect((new CrossrefFunderRorIdentifierNormalizer)->normalizeCrossrefFunderId($input, $allowBareSuffix))->toBeNull();
})->with([
    'null' => [null, false],
    'blank' => ['   ', false],
    'space in value' => ['10.13039/501100001659 extra', false],
    'query string' => ['https://doi.org/10.13039/501100001659?foo=bar', false],
    'fragment' => ['https://doi.org/10.13039/501100001659#section', false],
    'non-numeric suffix' => ['10.13039/abc', false],
    'bare suffix not allowed' => ['501100001659', false],
]);

it('canonicalizes supported ROR identifiers', function (string $input): void {
    expect((new CrossrefFunderRorIdentifierNormalizer)->canonicalRorIdentifier($input))->toBe('https://ror.org/018mejw64');
})->with([
    'https URL' => ['https://ror.org/018mejw64'],
    'http www URL with trailing slash and uppercase id' => ['http://www.ror.org/018MEJW64/'],
    'bare id' => ['018MEJW64'],
]);

it('rejects unsupported ROR identifiers', function (?string $input): void {
    expect((new CrossrefFunderRorIdentifierNormalizer)->canonicalRorIdentifier($input))->toBeNull();
})->with([
    'null' => [null],
    'blank' => [''],
    'too short' => ['018mejw6'],
    'too long' => ['018mejw640'],
    'does not start with zero URL' => ['https://ror.org/abcdef123'],
    'does not start with zero bare id' => ['abcdef123'],
    'does not end with two digits URL' => ['https://ror.org/018mejwx4'],
    'does not end with two digits bare id' => ['018mejwx4'],
    'wrong host' => ['https://example.org/018mejw64'],
    'path suffix' => ['https://ror.org/018mejw64/about'],
]);

it('returns only scalar non-empty filled strings', function (mixed $input, ?string $expected): void {
    expect((new CrossrefFunderRorIdentifierNormalizer)->filledString($input))->toBe($expected);
})->with([
    'null' => [null, null],
    'array' => [['value'], null],
    'object' => [(object) ['value' => 'x'], null],
    'blank' => ['   ', null],
    'string' => ['  Crossref Funder ID  ', 'Crossref Funder ID'],
    'zero' => [0, '0'],
]);
