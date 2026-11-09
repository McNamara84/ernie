<?php

declare(strict_types=1);

use App\Support\IdentifierUrl;

$cases = json_decode(file_get_contents(__DIR__.'/../../../fixtures/identifiers/url-cases.json'), true, flags: JSON_THROW_ON_ERROR);

it('resolves the same identifiers as the landing-page client', function (array $case) {
    expect(IdentifierUrl::resolve($case['value'], $case['type']))->toBe($case['url']);
})->with(array_map(fn (array $case): array => [$case], $cases));

it('rejects unsafe RDF IRI characters without making a resolver link', function (string $value) {
    expect(IdentifierUrl::resolve($value, 'DOI'))->toBeNull();
})->with(['10.5880/a b', '10.5880/a<b', "10.5880/a\nb", '10.5880/a"b']);
