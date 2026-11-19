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
            ->where('documentation.revision', 'v1')->has('documentation.formats', 2));
})->with([null, 'v1']);

test('serves directly addressable public JSON and JSON-LD references for the selected revision', function (?string $revision, string $format) {
    $this->get('/schemas/datacite/4.7/'.($revision === null ? '' : $revision.'/').'docs/'.$format)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('schemas/datacite-docs')
            ->where('auth.user', null)->where('documentation.format', $format)
            ->where('documentation.revision', 'v1')->where('documentation.syntax', 'json')
            ->where('documentation.referenceUrl', url('/schemas/datacite/4.7/v1/docs/'.$format))
            ->has('documentation.fields')->has('documentation.validation.steps'));
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
