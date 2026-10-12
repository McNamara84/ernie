<?php

declare(strict_types=1);

use App\Exceptions\JsonLdConversionException;
use App\Http\Controllers\JsonLdContextController;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteJsonLdContextService;
use App\Services\DataCiteLinkedDataExporter;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;

covers(DataCiteJsonLdContextService::class, JsonLdContextController::class);

test('publishes the exact immutable context without authentication or a session', function () {
    // The shared test case disables this middleware; exercise the real web stack.
    $this->withMiddleware(PreventRequestForgery::class);
    $content = file_get_contents(base_path(DataCiteJsonLdContextService::FILE));
    $response = $this->get(DataCiteJsonLdContextService::PATH);

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json; charset=UTF-8');
    expect($response->getContent())->toBe($content)
        ->and($response->headers->get('Cache-Control'))->toContain('public', 'immutable', 'max-age=31536000')
        ->and($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', $content).'"');

    $head = $this->head(DataCiteJsonLdContextService::PATH)->assertOk()->assertContent('');
    $etag = $this->get(DataCiteJsonLdContextService::PATH, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304)->assertContent('');
    $modified = $this->get(DataCiteJsonLdContextService::PATH, ['If-Modified-Since' => $response->headers->get('Last-Modified')])->assertStatus(304);
    foreach ([$head, $etag, $modified] as $cachedResponse) {
        expect($cachedResponse->headers->getCookies())->toBeEmpty();
    }
    $this->get(DataCiteJsonLdContextService::PATH, ['If-None-Match' => '"outdated"'])->assertOk()->assertContent($content);
});

test('keeps the normal web CSRF cookie endpoint active', function () {
    $this->withMiddleware(PreventRequestForgery::class);

    $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
});

test('resolves the public context from configuration rather than the request host', function (?string $override, string $expected) {
    config(['app.url' => 'https://public.example.org/ernie/', 'datacite.linked_data.context_url' => $override]);
    $this->withServerVariables(['HTTP_HOST' => 'untrusted.example.org']);

    expect(app(DataCiteJsonLdContextService::class)->url())->toBe($expected);
})->with([
    'default' => [null, 'https://public.example.org/ernie'.DataCiteJsonLdContextService::PATH],
    'blank' => ['  ', 'https://public.example.org/ernie'.DataCiteJsonLdContextService::PATH],
    'explicit override' => [' https://contexts.example.org/datacite.jsonld ', 'https://contexts.example.org/datacite.jsonld'],
]);

test('recognizes new exports, explicit overrides and known legacy profiles offline', function (string $context) {
    config(['app.url' => 'https://public.example.org', 'datacite.linked_data.context_url' => 'https://contexts.example.org/datacite.jsonld']);
    $document = (new DataCiteLinkedDataExporter)->exportAttributes(json_decode(file_get_contents(base_path('tests/pest/Fixtures/JsonLd/datacite-attributes.json')), true));
    $document['@context'] = $context;

    app(DataCiteJsonLdContextService::class)->assertSupportedDocument($document);
    expect($document['creators']['creator'][0]['creatorName']['value'])->toBe('Lovelace, Ada');
})->with([
    'local' => ['https://public.example.org'.DataCiteJsonLdContextService::PATH],
    'override' => ['https://contexts.example.org/datacite.jsonld'],
    'staging legacy' => [DataCiteJsonLdContextService::LEGACY_CONTEXTS[0]],
    'kernel legacy' => [DataCiteJsonLdContextService::LEGACY_CONTEXTS[1]],
]);

test('uploads new and legacy JSON-LD without losing related-item relation information', function (?string $legacy) {
    $this->seed([ResourceTypeSeeder::class, RelationTypeSeeder::class]);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'titles' => [['title' => 'Draft observations']],
        'creators' => [['name' => 'Lovelace, Ada', 'nameType' => 'Personal']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
        'relatedItems' => [['relatedItemType' => 'Presentation', 'relationType' => 'Other', 'relationTypeInformation' => 'Conference presentation', 'titles' => [['title' => 'Observation presentation']]]],
    ]);
    if ($legacy !== null) {
        $document['@context'] = $legacy;
        unset($document['@type']);
    }
    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('draft.jsonld', json_encode($document))]);
    $response->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    expect($resource->doi)->toBeNull()
        ->and($resource->relatedItems()->sole()->relation_type_information)->toBe('Conference presentation');
    expect(session()->get($response->json('sessionKey'))['relatedItems'][0]['relation_type_information'])->toBe('Conference presentation');
})->with([null, ...DataCiteJsonLdContextService::LEGACY_CONTEXTS]);

test('rejects invalid JSON-LD DOI ids before creating a draft', function (mixed $id) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'titles' => [['title' => 'DOI import observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['@id'] = $id;
    $before = Resource::count();

    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('invalid-doi.jsonld', json_encode($document))]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'json_ld_conversion_error');
    expect(Resource::count())->toBe($before);
})->with([
    'unrelated URL' => ['https://example.org/item'],
    'unrelated string' => ['not-a-doi'],
    'forged resolver host' => ['https://doi.org.example.org/10.5880/test'],
    'invalid resolver suffix' => ['https://doi.org/invalid'],
    'short DOI prefix' => ['https://doi.org/10.123/test'],
    'missing DOI suffix' => ['https://doi.org/10.5880/'],
    'embedded whitespace' => ['10.5880/invalid suffix'],
    'resolver whitespace' => ['https://doi.org/ 10.5880/test'],
    'empty id' => [''],
    'blank id' => ['  '],
    'null id' => [null],
    'numeric id' => [123],
    'array id' => [[]],
]);

test('normalizes supported JSON-LD DOI ids for draft storage', function (string $id) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'titles' => [['title' => 'DOI import observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['@id'] = $id;

    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('valid-doi.jsonld', json_encode($document))]);

    $response->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    expect($resource->doi)->toBe('10.5880/test.2026');
    expect(session()->get($response->json('sessionKey'))['doi'])->toBe('10.5880/Test.2026');
})->with([
    'bare DOI' => ['10.5880/Test.2026'],
    'https resolver' => ['https://doi.org/10.5880/Test.2026'],
    'http resolver' => ['http://doi.org/10.5880/Test.2026'],
    'legacy https resolver' => ['https://dx.doi.org/10.5880/Test.2026'],
    'legacy http resolver' => ['http://dx.doi.org/10.5880/Test.2026'],
    'case and surrounding whitespace' => ['  HTTPS://DOI.ORG/10.5880/Test.2026  '],
]);

test('rejects an explicitly invalid context on otherwise valid DataCite JSON before creating a draft', function (mixed $context, bool $envelope) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $attributes = [
        'titles' => [['title' => 'Context validation observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ];
    $document = ['@context' => $context, ...($envelope ? ['data' => ['attributes' => $attributes]] : $attributes)];
    $before = Resource::count();

    $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('invalid-context.json', json_encode($document))])
        ->assertStatus(422)->assertJsonPath('error.code', 'json_ld_conversion_error');

    expect(Resource::count())->toBe($before);
})->with([[null], [''], [false], [123], [[]], ['https://example.org/context.jsonld']])->with([false, true]);

test('rejects conflicting or malformed JSON-LD DOI identifiers before creating a draft', function (mixed $identifier, string $message) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'doi' => '10.5880/Test.2026',
        'titles' => [['title' => 'DOI consistency observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['identifier']['value'] = $identifier;
    $before = Resource::count();

    expect(fn () => app(DataCiteJsonLdContextService::class)->assertSupportedDocument($document))
        ->toThrow(JsonLdConversionException::class, $message);
    $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('conflicting-doi.jsonld', json_encode($document))])
        ->assertStatus(422)->assertJsonPath('error.code', 'json_ld_conversion_error');

    expect(Resource::count())->toBe($before);
})->with([
    'different bare DOI' => ['10.5880/other', 'DOI identifier must match @id'],
    'different resolver DOI' => ['https://dx.doi.org/10.5880/other', 'DOI identifier must match @id'],
    'unrelated URL' => ['https://example.org/item', 'DOI identifier must match @id'],
    'empty identifier' => ['', 'DOI identifier must match @id'],
    'null identifier value' => [null, 'identifier: expected a scalar value'],
    'numeric identifier' => [123, 'DOI identifier must match @id'],
]);

test('rejects scalar JSON-LD identifiers before creating a draft', function (mixed $identifier, bool $withDoi, ?string $legacy) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        ...($withDoi ? ['doi' => '10.5880/Test.2026'] : []),
        'titles' => [['title' => 'Identifier shape observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['identifier'] = $identifier;
    if ($legacy !== null) {
        $document['@context'] = $legacy;
    }
    $before = Resource::count();

    $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('scalar-identifier.jsonld', json_encode($document))])
        ->assertStatus(422)->assertJsonPath('error.code', 'json_ld_conversion_error');
    expect(Resource::count())->toBe($before);
})->with(['10.5880/other', '10.5880/Test.2026', null, false, 123])->with([false, true])->with([null, ...DataCiteJsonLdContextService::LEGACY_CONTEXTS]);

test('rejects incomplete value objects and null metadata before creating a draft', function (array $changes, string $contextName) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    config(['app.url' => 'https://public.example.org', 'datacite.linked_data.context_url' => 'https://contexts.example.org/datacite.jsonld']);
    $context = app(DataCiteJsonLdContextService::class);
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'titles' => [['title' => 'Literal validation observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document = array_replace($document, $changes);
    $document['@context'] = match ($contextName) {
        'local' => $context->localUrl(),
        'mirror' => $context->url(),
        'staging legacy' => DataCiteJsonLdContextService::LEGACY_CONTEXTS[0],
        'kernel legacy' => DataCiteJsonLdContextService::LEGACY_CONTEXTS[1],
    };
    $before = Resource::count();

    $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('invalid-literals.jsonld', json_encode($document))])
        ->assertStatus(422)->assertJsonPath('error.code', 'json_ld_conversion_error');
    expect(Resource::count())->toBe($before);
})->with([
    'identifier without value' => [['identifier' => ['attrs' => ['identifierType' => 'DOI']]]],
    'identifier with null value' => [['identifier' => ['attrs' => ['identifierType' => 'DOI'], 'value' => null]]],
    'null version' => [['version' => null]],
    'version with null value' => [['version' => ['value' => null]]],
    'version without value' => [['version' => ['attrs' => []]]],
    'null language' => [['language' => null]],
    'title without value' => [['titles' => ['title' => ['attrs' => ['lang' => 'en']]]]],
    'null given name' => [['creators' => ['creator' => ['creatorName' => ['value' => 'Example Observatory'], 'givenName' => null]]]],
    'null funding award number' => [['fundingReferences' => ['fundingReference' => ['funderName' => ['value' => 'Example'], 'awardNumber' => null]]]],
    'nested related item identifier without value' => [['relatedItems' => ['relatedItem' => ['attrs' => ['relatedItemType' => 'Other', 'relationType' => 'Other'], 'value' => ['relatedItemIdentifier' => ['attrs' => ['relatedItemIdentifierType' => 'DOI']]]]]]],
    'null nested related item volume' => [['relatedItems' => ['relatedItem' => ['attrs' => ['relatedItemType' => 'Other', 'relationType' => 'Other'], 'value' => ['volume' => null]]]]],
    'null publisher attribute' => [['publisher' => ['value' => 'Example Publisher', 'attrs' => ['publisherIdentifier' => null]]]],
    'null structural attribute' => [['relatedItems' => ['relatedItem' => ['attrs' => ['relatedItemType' => 'Other', 'relationType' => 'Other', 'relationTypeInformation' => null]]]]],
    'null resource type keyword' => [['@type' => null]],
])->with(['local', 'mirror', 'staging legacy', 'kernel legacy']);

test('uploads zero scalar metadata without losing values', function (mixed $value, bool $wrapped) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'titles' => [['title' => 'Zero version observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['version'] = $wrapped ? ['value' => $value] : $value;
    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('zero-version.jsonld', json_encode($document))]);

    $response->assertOk();
    expect(Resource::findOrFail($response->json('resourceId'))->version)->toBe('0')
        ->and(session()->get($response->json('sessionKey'))['version'])->toBe('0');
})->with(['0', 0])->with([false, true]);

test('uploads equivalent JSON-LD DOI identifiers with one normalized DOI', function (string $identifier) {
    $this->seed(ResourceTypeSeeder::class);
    $this->actingAs(User::factory()->create());
    $document = (new DataCiteLinkedDataExporter)->exportAttributes([
        'doi' => '10.5880/Test.2026',
        'titles' => [['title' => 'DOI consistency observations']],
        'creators' => [['name' => 'Example Observatory', 'nameType' => 'Organizational']],
        'publisher' => ['name' => 'Example Publisher'], 'publicationYear' => '2026',
        'types' => ['resourceTypeGeneral' => 'Dataset'],
    ]);
    $document['identifier']['value'] = $identifier;
    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('matching-doi.jsonld', json_encode($document))]);

    $response->assertOk();
    expect(Resource::findOrFail($response->json('resourceId'))->doi)->toBe('10.5880/test.2026')
        ->and(session()->get($response->json('sessionKey'))['doi'])->toBe('10.5880/Test.2026');
})->with(['10.5880/test.2026', 'https://doi.org/10.5880/TEST.2026', 'http://dx.doi.org/10.5880/test.2026', '  HTTPS://DX.DOI.ORG/10.5880/test.2026  ']);

test('rejects unsupported profiles and incompatible structures before creating a draft', function (array $changes, string $message) {
    $this->actingAs(User::factory()->create());
    $document = ['@context' => app(DataCiteJsonLdContextService::class)->url(), ...$changes];
    $before = Resource::count();

    expect(fn () => app(DataCiteJsonLdContextService::class)->assertSupportedDocument($document))->toThrow(JsonLdConversionException::class, $message);

    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('metadata.jsonld', json_encode($document))]);
    $response->assertStatus(422);
    expect(Resource::count())->toBe($before);
})->with([
    'schema.org' => [['@context' => 'https://schema.org/'], 'Unsupported JSON-LD context'],
    'context array' => [['@context' => ['https://schema.org/']], 'Unsupported JSON-LD context'],
    'inline context' => [['@context' => ['@vocab' => 'https://schema.org/']], 'Unsupported JSON-LD context'],
    'unknown context' => [['@context' => 'https://example.org/context.jsonld'], 'Unsupported JSON-LD context'],
    'graph' => [['@graph' => []], 'Unsupported DataCite JSON-LD field: @graph'],
    'wrong type' => [['@type' => 'Dataset'], 'Unsupported DataCite JSON-LD resource type'],
    'invalid id' => [['@id' => []], 'expected a string'],
    'identifier list' => [['identifier' => [['value' => '10.5880/example', 'attrs' => ['identifierType' => 'DOI']]]], 'expected a single value or object'],
    'empty identifier list' => [['identifier' => []], 'expected a single value or object'],
    'publisher list' => [['publisher' => [['value' => 'Example Publisher']]], 'expected a single value or object'],
    'nested name list' => [['creators' => ['creator' => [['creatorName' => [['value' => 'Ada']]]]]], 'expected a single value or object'],
    'flat API creators' => [['creators' => [['name' => 'Ada']]], 'missing creatorName'],
    'scalar collection' => [['titles' => 'Research'], 'expected an object or list'],
    'scalar entry' => [['creators' => ['creator' => ['Ada']]], 'expected an object'],
    'unknown nested field' => [['titles' => ['title' => ['value' => 'Research', 'lostField' => 'do not discard']]], 'lostField'],
    'nested context' => [['titles' => ['title' => ['@context' => 'https://schema.org/', 'value' => 'Research']]], 'Unsupported DataCite JSON-LD field: @context'],
    'known term in wrong node' => [['titles' => ['title' => ['value' => 'Research', 'attrs' => ['dateType' => 'Issued']]]], 'dateType'],
    'known term next to wrapper' => [['titles' => ['title' => ['value' => 'Research'], 'description' => ['value' => 'Lost']]], 'description'],
    'literal array' => [['titles' => ['title' => ['value' => ['publisher' => 'Lost']]]], 'expected a scalar value'],
    'scalar attrs' => [['titles' => ['title' => ['value' => 'Research', 'attrs' => 'en']]], 'expected an attrs object'],
    'array attrs' => [['titles' => ['title' => ['value' => 'Research', 'attrs' => ['lang' => ['en']]]]], 'expected scalar attributes'],
    'malformed nested related item' => [['relatedItems' => ['relatedItem' => ['value' => 'Research']]], 'expected an object'],
    'nested related item list' => [['relatedItems' => ['relatedItem' => ['value' => [['titles' => ['title' => ['value' => 'Research']]]]]]], 'expected an object'],
]);
