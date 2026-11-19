<?php

declare(strict_types=1);

use App\Http\Controllers\DataCiteDocumentationController;
use App\Services\DataCite\DataCiteDocumentationService;
use App\Services\DataCite\DataCiteSchemaPublicationService;
use App\Services\DataCite\JsonSchemaDocumentationReaderService;
use App\Services\DataCiteJsonImportNormalizerService;
use App\Services\DataCiteJsonLdContextService;
use App\Services\DataCiteJsonLdToJsonConverterService;
use Inertia\Testing\AssertableInertia as Assert;
use Opis\JsonSchema\Validator;

covers(DataCiteDocumentationController::class, DataCiteDocumentationService::class, JsonSchemaDocumentationReaderService::class);

test('redirects the versionless DataCite guide to its versioned entry point', function () {
    $this->get('/schemas/datacite/docs')->assertRedirect('/schemas/datacite/4.7/docs');
});

test('offers both DataCite guides publicly from current and fixed overviews', function (?string $revision) {
    $this->get('/schemas/datacite/4.7/'.($revision === null ? '' : $revision.'/').'docs')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('schemas/datacite-docs-overview')
            ->where('auth.user', null)->where('documentation.locale', 'en')
            ->where('documentation.revision', 'v1')->has('documentation.formats', 2)
            ->where('documentation.citation.url', 'https://doi.org/10.14454/qdd3-ps68')
            ->where('documentation.citation.text', fn (string $text): bool => str_contains($text, 'DataCite Metadata Working Group. (2026).')));
})->with([null, 'v1']);

test('serves directly addressable public JSON and JSON-LD references for the selected revision', function (?string $revision, string $format) {
    $this->get('/schemas/datacite/4.7/'.($revision === null ? '' : $revision.'/').'docs/'.$format)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('schemas/datacite-docs')
            ->where('auth.user', null)->where('documentation.format', $format)
            ->where('documentation.revision', 'v1')->where('documentation.syntax', 'json')
            ->where('documentation.referenceUrl', url('/schemas/datacite/4.7/v1/docs/'.$format))
            ->has('documentation.fields')->has('documentation.validation.steps')
            ->where('documentation.citation.url', 'https://doi.org/10.14454/qdd3-ps68'));
})->with([null, 'v1'])->with(['json', 'jsonld']);

test('derives JSON rules including references, alternatives and conditional rules from the published schema', function () {
    $doc = app(DataCiteDocumentationService::class)->document('json');
    $fields = collect($doc['fields'])->keyBy('path');
    expect($fields['$.creators']['required'])->toBeTrue()
        ->and($fields['$.doi']['required'])->toBeFalse()
        ->and($fields['$.types.resourceTypeGeneral']['values'])->toContain('Poster', 'Presentation')
        ->and($fields['$.publisher']['type'])->toContain('string', 'object')
        ->and($fields['$.publisher.name']['conditional'])->toBeTrue()
        ->and($fields['$.relatedIdentifiers[].relatedIdentifierType']['values'])->toContain('RAiD', 'SWHID')
        ->and(collect($fields['$.geoLocations[].geoLocationPoint.pointLatitude']['constraints'])->firstWhere('name', 'minimum')['value'])->toBe('-90')
        ->and(collect($fields['$.relatedItems']['constraints'])->firstWhere('name', 'conditionalRules')['value'])->toContain('numberType', 'number');
    $relatedRules = json_decode(collect($fields['$.relatedIdentifiers']['constraints'])->firstWhere('name', 'conditionalRules')['value'], true);
    expect($relatedRules)->not->toHaveKey('then')
        ->and($relatedRules['allOf'][0]['then']['properties']['relatedIdentifier']['format'])->toBe('uri')
        ->and($fields['$.relatedIdentifiers[].relationTypeInformation']['required'])->toBeFalse();
    foreach ($doc['fields'] as $field) {
        expect($field['description'])->not->toBe('');
        foreach ($field['children'] as $id) {
            expect(collect($doc['fields'])->contains('id', $id))->toBeTrue();
        }
    }
});

test('documents actual compact-profile paths and context meaning without inventing support for JSON-only fields', function () {
    $doc = app(DataCiteDocumentationService::class)->document('jsonld');
    $fields = collect($doc['fields'])->keyBy('id');
    $name = $fields['datacite-creators-name'];
    expect($name['sourcePath'])->toBe('$.creators[].name')
        ->and($name['path'])->toBe('$.creators.creator[].creatorName.value')
        ->and($name['semantic']['iri'])->toBe('https://w3id.org/tib/datacite/property/creatorName')
        ->and($fields['datacite-creators']['semantic']['container'])->toBe('@list')
        ->and($fields['datacite-types-resourceTypeGeneral']['example'])->toContain('"resourceType"', '"attrs"', '"resourceTypeGeneral"')
        ->and($fields['datacite-jsonld-context']['required'])->toBeTrue()
        ->and($fields->has('datacite-container'))->toBeFalse()
        ->and($fields->has('datacite-identifiers'))->toBeFalse()
        ->and($fields->has('datacite-creators-lang'))->toBeFalse()
        ->and($doc['schemaUrl'])->toEndWith('/v1/context.jsonld')
        ->and($doc['copy']['semanticRuleExplanation'])->toContain('after conversion');
    foreach ($doc['fields'] as $field) {
        foreach ($field['children'] as $id) {
            expect($fields->has($id))->toBeTrue();
        }
    }
});

test('keeps polygon item requirements within their exclusive alternatives', function (string $format) {
    $fields = collect(app(DataCiteDocumentationService::class)->document($format)['fields'])->keyBy('id');
    $id = 'datacite-geoLocations-geoLocationPolygon';
    $polygon = $fields[$id];

    expect($polygon['alternatives'] ?? null)->toBe([
        'keyword' => 'oneOf',
        'scope' => 'items',
        'branches' => [
            ['types' => ['object'], 'required' => [['name' => 'polygonPoint', 'fieldId' => $id.'-polygonPoint']]],
            ['types' => ['object'], 'required' => [['name' => 'inPolygonPoint', 'fieldId' => $id.'-inPolygonPoint']]],
        ],
    ]);
    foreach (['polygonPoint', 'inPolygonPoint'] as $name) {
        $field = $fields[$id.'-'.$name];
        expect($field['required'])->toBeFalse()
            ->and($field['conditional'])->toBeTrue()
            ->and($field['min'])->toBe('0')
            ->and($fields[$id.'-'.$name.'-pointLatitude']['required'])->toBeTrue()
            ->and($fields[$id.'-'.$name.'-pointLatitude']['conditional'])->toBeTrue();
    }
    $rule = json_decode(collect($polygon['constraints'])->firstWhere('name', 'oneOf')['value'], true);
    expect(array_column($rule, 'required'))->toBe([['polygonPoint'], ['inPolygonPoint']]);
})->with(['json', 'jsonld']);

test('documents publisher name as required only in the object alternative', function () {
    $fields = collect(app(DataCiteDocumentationService::class)->document('json')['fields'])->keyBy('path');

    expect($fields['$.publisher.name']['required'])->toBeFalse()
        ->and($fields['$.publisher.name']['conditional'])->toBeTrue()
        ->and($fields['$.publisher.name']['min'])->toBe('0')
        ->and($fields['$.publisher']['alternatives']['keyword'] ?? null)->toBe('oneOf')
        ->and($fields['$.publisher']['alternatives']['branches'][0]['required'] ?? null)->toBe([])
        ->and($fields['$.publisher']['alternatives']['branches'][1]['required'] ?? null)
        ->toBe([['name' => 'name', 'fieldId' => 'datacite-publisher-name']]);
});

test('keeps base and shared obligations required across alternatives', function (string $keyword) {
    $text = json_decode(file_get_contents(resource_path('data/schema-docs/datacite/4.7/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $schema = [
        'type' => 'object',
        'properties' => ['identifier' => ['type' => 'string']],
        'required' => ['identifier'],
        $keyword => array_map(fn (string $name): array => [
            'type' => 'object',
            'properties' => ['shared' => ['type' => 'string'], $name => ['type' => 'string']],
            'required' => ['shared', $name],
        ], ['left', 'right']),
    ];
    $fields = collect(app(JsonSchemaDocumentationReaderService::class)->fields($schema, $text))->keyBy('path');

    foreach (['identifier', 'shared'] as $name) {
        expect($fields['$.'.$name]['required'])->toBeTrue()->and($fields['$.'.$name]['min'])->toBe('1');
    }
    foreach (['left', 'right'] as $name) {
        expect($fields['$.'.$name]['required'])->toBeFalse()
            ->and($fields['$.'.$name]['conditional'])->toBeTrue()
            ->and($fields['$.'.$name]['min'])->toBe('0');
    }
})->with(['oneOf', 'anyOf']);

test('accepts separate polygon alternatives but rejects combined or missing alternatives', function () {
    $publication = app(DataCiteSchemaPublicationService::class)->publicFile('schema.json', 'v1');
    $schema = json_decode(file_get_contents($publication['path']));
    $record = json_decode(app(DataCiteDocumentationService::class)->example('json', 'minimal'), true);
    $point = ['pointLatitude' => 52.0, 'pointLongitude' => 13.0];
    $polygon = array_map(fn (array $coordinates): array => ['polygonPoint' => $coordinates], [
        $point,
        ['pointLatitude' => 53.0, 'pointLongitude' => 13.0],
        ['pointLatitude' => 52.0, 'pointLongitude' => 14.0],
        $point,
    ]);
    $valid = function (array $items) use ($record, $schema): bool {
        $record['geoLocations'] = [['geoLocationPolygon' => $items]];

        return (new Validator)->validate(json_decode(json_encode($record, JSON_THROW_ON_ERROR)), $schema)->isValid();
    };

    $interior = ['inPolygonPoint' => ['pointLatitude' => 52.25, 'pointLongitude' => 13.25]];
    expect($valid($polygon))->toBeTrue()
        ->and($valid([...$polygon, $interior]))->toBeTrue()
        ->and($valid([...$polygon, $interior, $interior]))->toBeFalse()
        ->and($valid([...array_slice($polygon, 1), $interior]))->toBeFalse()
        ->and($valid([['polygonPoint' => $point, 'inPolygonPoint' => $point], ...array_slice($polygon, 1)]))->toBeFalse()
        ->and($valid([new stdClass, ...array_slice($polygon, 1)]))->toBeFalse();
});

test('downloads schema-valid examples and JSON-LD that passes profile conversion and validation', function (string $format, string $name) {
    $schemaFile = app(DataCiteSchemaPublicationService::class)->publicFile('schema.json', 'v1');
    $schema = json_decode(file_get_contents($schemaFile['path']));
    $json = $this->get('/schemas/datacite/4.7/v1/docs/'.$format.'/examples/'.$name.'.'.$format)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="datacite-4.7-'.$name.'.'.$format.'"')->getContent();
    $object = json_decode($json);
    if ($format === 'jsonld') {
        $record = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        app(DataCiteJsonLdContextService::class)->assertSupportedDocument($record);
        expect($record['@context'])->toEndWith('/schemas/datacite/4.7/v1/context.jsonld');
        $attributes = app(DataCiteJsonLdToJsonConverterService::class)->convert($record);
        $attributes = app(DataCiteJsonImportNormalizerService::class)->normalize($attributes);
        $object = json_decode(json_encode($attributes, JSON_THROW_ON_ERROR));
    }
    $validation = (new Validator)->validate($object, $schema);
    expect($validation->isValid())->toBeTrue();
})->with(['json', 'jsonld'])->with(['minimal', 'full']);

test('rejects unknown revisions formats examples and mismatched file extensions', function (string $path) {
    $this->get('/schemas/datacite/4.7/'.$path)->assertNotFound();
})->with(['v99/docs', 'v99/docs/json', 'docs/xml', 'docs/json/examples/secret.json', 'docs/json/examples/minimal.jsonld', 'v1/docs/jsonld/examples/full.json']);

test('falls back to English when another documentation language is unavailable', function () {
    config(['datacite.schemas.documentation_locale' => 'de']);
    $this->get('/schemas/datacite/4.7/docs/json')->assertOk()->assertInertia(fn (Assert $page) => $page->where('documentation.locale', 'en'));
});
