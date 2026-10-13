<?php

declare(strict_types=1);

use App\Exceptions\JsonLdConversionException;
use App\Services\DataCiteJsonLdContextService;

covers(DataCiteJsonLdContextService::class);

it('rejects missing or non-scalar values throughout the compact profile', function (array $fields, string $term) {
    $context = app(DataCiteJsonLdContextService::class);
    $document = ['@context' => $context->url(), ...$fields];

    expect(fn () => $context->assertSupportedDocument($document))
        ->toThrow(JsonLdConversionException::class, $term.': expected a scalar value.');
})->with([
    'identifier without value' => [['identifier' => ['attrs' => ['identifierType' => 'DOI']]], 'identifier'],
    'identifier with null value' => [['identifier' => ['value' => null]], 'identifier'],
    'identifier with array value' => [['identifier' => ['value' => []]], 'identifier'],
    'null version' => [['version' => null], 'version'],
    'version without value' => [['version' => ['attrs' => []]], 'version'],
    'version with null value' => [['version' => ['value' => null]], 'version'],
    'null language' => [['language' => null], 'language'],
    'null publication year' => [['publicationYear' => null], 'publicationYear'],
    'publisher without value' => [['publisher' => ['attrs' => ['lang' => 'en']]], 'publisher'],
    'title without value' => [['titles' => ['title' => ['attrs' => ['lang' => 'en']]]], 'title'],
    'null title in a list' => [['titles' => ['title' => [['value' => 'Research'], null]]], 'title'],
    'creator name without value' => [['creators' => ['creator' => ['creatorName' => ['attrs' => ['nameType' => 'Personal']]]]], 'creatorName'],
    'null given name' => [['creators' => ['creator' => ['creatorName' => ['value' => 'Example'], 'givenName' => null]]], 'givenName'],
    'null name identifier' => [['creators' => ['creator' => ['creatorName' => ['value' => 'Example'], 'nameIdentifier' => null]]], 'nameIdentifier'],
    'affiliation without value' => [['creators' => ['creator' => ['creatorName' => ['value' => 'Example'], 'affiliation' => ['attrs' => ['affiliationIdentifierScheme' => 'ROR']]]]], 'affiliation'],
    'related item identifier without value' => [['relatedItems' => ['relatedItem' => ['value' => ['relatedItemIdentifier' => ['attrs' => ['relatedItemIdentifierType' => 'DOI']]]]]], 'relatedItemIdentifier'],
    'null nested related item volume' => [['relatedItems' => ['relatedItem' => ['value' => ['volume' => null]]]], 'volume'],
    'null funding award number' => [['fundingReferences' => ['fundingReference' => ['funderName' => ['value' => 'Example'], 'awardNumber' => null]]], 'awardNumber'],
    'null point coordinate' => [['geoLocations' => ['geoLocation' => ['geoLocationPoint' => ['pointLongitude' => null, 'pointLatitude' => ['value' => 0]]]]], 'pointLongitude'],
]);

it('rejects null metadata attributes instead of treating them as absent', function (array $fields) {
    $context = app(DataCiteJsonLdContextService::class);

    expect(fn () => $context->assertSupportedDocument(['@context' => $context->url(), ...$fields]))
        ->toThrow(JsonLdConversionException::class, 'expected scalar attributes.');
})->with([
    'literal attribute' => [['publisher' => ['value' => 'Example', 'attrs' => ['publisherIdentifier' => null]]]],
    'structural attribute' => [['relatedItems' => ['relatedItem' => ['attrs' => ['relatedItemType' => 'Other', 'relationTypeInformation' => null]]]]],
]);

it('rejects an explicitly null resource type keyword', function () {
    $context = app(DataCiteJsonLdContextService::class);

    expect(fn () => $context->assertSupportedDocument(['@context' => $context->url(), '@type' => null]))
        ->toThrow(JsonLdConversionException::class, 'Unsupported DataCite JSON-LD resource type.');
});

it('accepts present scalar values without a truthiness requirement', function (mixed $value) {
    $context = app(DataCiteJsonLdContextService::class);
    $document = ['@context' => $context->url(), 'version' => ['value' => $value]];
    $context->assertSupportedDocument($document);
    $context->assertSupportedDocument(['@context' => $context->url(), 'version' => $value]);

    expect($document['version']['value'])->toBe($value);
})->with(['zero string' => ['0'], 'zero integer' => [0], 'zero float' => [0.0], 'empty string' => [''], 'false' => [false], 'true' => [true]]);

it('keeps optional fields absent and preserves structural objects without literal values', function () {
    $context = app(DataCiteJsonLdContextService::class);
    $document = [
        '@context' => $context->url(),
        'resourceType' => ['value' => '', 'attrs' => ['resourceTypeGeneral' => 'Dataset']],
        'relatedItems' => ['relatedItem' => ['attrs' => ['relatedItemType' => 'Other', 'relationType' => 'Other']]],
        'geoLocations' => ['geoLocation' => ['geoLocationPoint' => ['pointLongitude' => ['value' => 0], 'pointLatitude' => ['value' => 0]]]],
    ];
    $context->assertSupportedDocument($document);

    expect($document)->not->toHaveKey('version')
        ->and($document['resourceType']['value'])->toBe('')
        ->and($document['relatedItems']['relatedItem'])->not->toHaveKey('value');
});
