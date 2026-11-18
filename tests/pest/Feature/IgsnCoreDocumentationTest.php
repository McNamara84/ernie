<?php

declare(strict_types=1);

use App\Http\Controllers\IgsnCoreDocumentationController;
use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use App\Services\Igsn\Export\IgsnCoreXmlValidator;
use App\Services\Igsn\IgsnCoreDocumentationService;
use Inertia\Testing\AssertableInertia as Assert;

covers(IgsnCoreDocumentationController::class, IgsnCoreDocumentationService::class);

test('serves the English schema explorer publicly for the current and fixed revisions', function (?string $revision) {
    $path = '/schemas/igsn/2.0/'.($revision === null ? '' : $revision.'/').'docs';
    $expected = $revision ?? 'v1';
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('schemas/igsn-core-docs')
        ->where('auth.user', null)
        ->where('documentation.locale', 'en')
        ->where('documentation.revision', $expected)
        ->where('documentation.version', '2.0')
        ->where('documentation.namespace', 'urn:FAIR-AIMS:Core:1')
        ->has('documentation.fields')
        ->where('documentation.schemaUrl', url('/schemas/igsn/2.0/'.$expected.'/core.xsd')));
})->with([null, 'v1', 'preview-1']);

test('derives actual XSD requirements and namespaces instead of copying incompatible PDF examples', function () {
    $doc = app(IgsnCoreDocumentationService::class)->document('v1');
    $fields = collect($doc['fields'])->keyBy('path');
    $root = '/igsn:resource';
    expect($fields->has($root.'/igsn:agents/igsn:creator'))->toBeFalse()
        ->and($fields->has($root.'/igsn:archives'))->toBeFalse()
        ->and($fields->has($root.'/igsn:entityTypeClassifications/igsn:sampleType'))->toBeTrue();
    $contributor = $fields[$root.'/igsn:agents/igsn:contributor'];
    expect($contributor['min'])->toBe('0')->and($contributor['max'])->toBe('unbounded');
    $role = collect($contributor['attributes'])->firstWhere('name', 'contributorType');
    expect($role['required'])->toBeFalse()->and($role['values'])->toContain('DataCollector', 'ProjectLeader');
    $name = $fields[$root.'/igsn:agents/igsn:contributor/igsn:contributorName'];
    expect($name['conditional'])->toBeTrue();
    $identifier = $fields[$root.'/igsn:agents/igsn:contributor/igsn:nameIdentifier'];
    expect(collect($identifier['attributes'])->firstWhere('name', 'nameIdentifierScheme')['qname'])->toBe('nameIdentifierScheme');
    $sampleType = $fields[$root.'/igsn:entityTypeClassifications/igsn:sampleType'];
    expect(collect($sampleType['attributes'])->pluck('qname')->all())->toContain('igsn:sampletScheme')->not->toContain('igsn:sampleTypeScheme');
    expect($sampleType['example'])->toContain('igsn:sampleType', 'igsn:sampletScheme');
    foreach ($doc['fields'] as $field) {
        expect($field['description'])->not->toBe('');
        foreach ($field['children'] as $id) {
            expect(collect($doc['fields'])->contains('id', $id))->toBeTrue();
        }
    }
    expect(collect($doc['fields'])->pluck('id')->unique()->count())->toBe(count($doc['fields']));
});

test('keeps the earlier schema reference available with its own structure', function () {
    $doc = app(IgsnCoreDocumentationService::class)->document('preview-1');
    expect(collect($doc['fields'])->pluck('name')->all())->toContain('creator', 'archives')->not->toContain('sampleType');
});

test('falls back to English when a translation is not available', function () {
    config(['igsn_core.documentation_locale' => 'de']);

    $this->get('/schemas/igsn/2.0/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documentation.locale', 'en')
        ->where('documentation.copy.search', 'Search the schema'));
});

test('downloads examples that validate against exactly their documented schema revision', function (string $revision, string $example) {
    $xml = $this->get('/schemas/igsn/2.0/'.$revision.'/docs/examples/'.$example.'.xml')
        ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="igsn-core-2.0-'.$example.'.xml"')
        ->getContent();
    config(['igsn_core.revision' => $revision]);
    expect(app(IgsnCoreXmlValidator::class)->validate($xml)->errors)->toBe([])
        ->and($xml)->toContain('/'.$revision.'/core.xsd');
})->with(['v1', 'preview-1'])->with(['minimal', 'full']);

test('only changes the upstream import location in the published Core XSD', function () {
    $original = file_get_contents(resource_path('data/scheme/igsn-core/2.0/e28072729362b9fe740b64e8313c9add4364f12a/upstream/IGSN_Core_by_GFZ_DataServices.xsd'));
    expect(hash('sha256', $original))->toBe('29e3cd836ae3ec2779599d85bb083a7118d44bb739c519dce7adb139c0d2c90b');
    $expected = str_replace(
        'schemaLocation="urn:FAIR-AIMS:Core:1 https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd"',
        'schemaLocation="DataCite4.7/metadata.xsd"',
        $original,
    );
    expect(file_get_contents(app(IgsnCoreSchemaResolver::class)->publicFile('core.xsd', 'v1')['path']))->toBe($expected)
        ->and(hash_file('sha256', app(IgsnCoreSchemaResolver::class)->publicFile('core.xsd', 'preview-1')['path']))
        ->toBe('e8f3fd681dd1af577306fe85cdf24a8cc9c10444795ae672d8952f3579f210cf');
});

test('rejects unknown documentation revisions and example names', function (string $path) {
    $this->get('/schemas/igsn/2.0/'.$path)->assertNotFound();
})->with(['v99/docs', 'docs/examples/secret.xml', 'v1/docs/examples/secret.xml', 'v99/docs/examples/full.xml']);
