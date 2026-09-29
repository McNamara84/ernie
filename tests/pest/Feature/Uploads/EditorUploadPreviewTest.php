<?php

declare(strict_types=1);

use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function editorPreviewXml(string $doi = '10.5880/preview', string $relatedItems = ''): UploadedFile
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">{$doi}</identifier>
    <titles><title>Imported title</title></titles>
    {$relatedItems}
</resource>
XML;

    return UploadedFile::fake()->createWithContent('import.xml', $xml);
}

test('editor XML preview returns parsed metadata without creating a draft or session', function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    $existing = Resource::factory()->create(['doi' => '10.5880/preview']);
    $before = Resource::count();

    $this->postJson(route('editor.upload-xml.preview'), ['file' => editorPreviewXml()])
        ->assertOk()
        ->assertJsonPath('metadata.doi', '10.5880/preview')
        ->assertJsonPath('metadata.titles.0.title', 'Imported title')
        ->assertJsonMissingPath('resourceId')
        ->assertJsonMissingPath('sessionKey');

    expect(Resource::count())->toBe($before)
        ->and(Resource::find($existing->id)?->doi)->toBe('10.5880/preview');
});

test('XML citations survive both draft save and autosave', function (string $intent) {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    ResourceType::create(['name' => 'Journal Article', 'slug' => 'journal-article']);
    RelationType::create(['name' => 'Cites', 'slug' => 'Cites']);

    $xml = '<relatedItems><relatedItem relatedItemType="JournalArticle" relationType="Cites">'
        .'<relatedItemIdentifier relatedItemIdentifierType="DOI">10.1234/cited</relatedItemIdentifier>'
        .'<titles><title>Cited article</title></titles>'
        .'<creators><creator><creatorName nameType="Personal">Doe, Jane</creatorName></creator></creators>'
        .'</relatedItem></relatedItems>';
    $items = $this->postJson(route('editor.upload-xml.preview'), ['file' => editorPreviewXml(relatedItems: $xml)])
        ->assertOk()
        ->json('metadata.relatedItems');

    expect($items)->toHaveCount(1);
    $this->postJson('/editor/resources/draft', [
        'intent' => $intent,
        'titles' => [['title' => 'Imported title', 'titleType' => 'main-title']],
        'relatedItems' => $items,
    ])->assertCreated();

    $item = Resource::latest('id')->firstOrFail()->relatedItems()->with(['titles', 'creators'])->sole();
    expect($item->related_item_type)->toBe('JournalArticle')
        ->and($item->identifier)->toBe('10.1234/cited')
        ->and($item->titles->sole()->title)->toBe('Cited article')
        ->and($item->creators->sole()->name)->toBe('Doe, Jane');
})->with(['save-draft', 'autosave']);

test('draft validation rejects incomplete inline citations', function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    ResourceType::create(['name' => 'Journal Article', 'slug' => 'journal-article']);
    RelationType::create(['name' => 'Cites', 'slug' => 'Cites']);

    $this->postJson('/editor/resources/draft', [
        'intent' => 'save-draft',
        'titles' => [['title' => 'Draft title', 'titleType' => 'main-title']],
        'relatedItems' => [[
            'related_item_type' => 'JournalArticle',
            'relation_type_slug' => 'Cites',
            'titles' => [],
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('relatedItems.0.titles');

    expect(Resource::count())->toBe(0);
});

test('editor JSON preview returns parsed metadata without creating a draft', function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    $before = Resource::count();
    $file = UploadedFile::fake()->createWithContent('import.json', dataCiteJson(minimalAttributes()));

    $this->postJson(route('editor.upload-json.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('metadata.titles.0.title', 'Test Dataset')
        ->assertJsonMissingPath('resourceId');

    expect(Resource::count())->toBe($before);
});

test('editor JSON preview normalizes inline citations and persists them on draft save', function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    ResourceType::create(['name' => 'Journal Article', 'slug' => 'journal-article']);
    RelationType::create(['name' => 'Cites', 'slug' => 'Cites']);

    $attributes = minimalAttributes([
        'relatedItems' => [[
            'relatedItemType' => 'JournalArticle',
            'relationType' => 'Cites',
            'relatedItemIdentifier' => [
                'relatedItemIdentifier' => '10.1234/json-citation',
                'relatedItemIdentifierType' => 'DOI',
            ],
            'titles' => [['title' => 'JSON citation']],
            'creators' => [['name' => 'Smith, John', 'nameType' => 'Personal']],
            'publicationYear' => '2024',
        ]],
    ]);
    $items = $this->postJson(route('editor.upload-json.preview'), [
        'file' => UploadedFile::fake()->createWithContent('citation.json', dataCiteJson($attributes)),
    ])->assertOk()
        ->assertJsonPath('metadata.relatedItems.0.identifier', '10.1234/json-citation')
        ->assertJsonPath('metadata.relatedItems.0.titles.0.title_type', 'MainTitle')
        ->assertJsonPath('metadata.relatedItems.0.creators.0.name', 'Smith, John')
        ->assertJsonPath('metadata.relatedItems.0.publication_year', 2024)
        ->json('metadata.relatedItems');

    $this->postJson('/editor/resources/draft', [
        'intent' => 'save-draft',
        'titles' => [['title' => 'JSON import', 'titleType' => 'main-title']],
        'relatedItems' => $items,
    ])->assertCreated();

    expect(Resource::latest('id')->firstOrFail()->relatedItems()->sole()->identifier)->toBe('10.1234/json-citation');
});

test('editor JSON-LD preview includes inline citations', function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    $jsonLd = [
        '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
        'titles' => ['title' => ['value' => 'JSON-LD import']],
        'creators' => ['creator' => ['creatorName' => ['value' => 'Doe, Jane']]],
        'publisher' => ['value' => 'GFZ Data Services'],
        'publicationYear' => ['value' => '2025'],
        'resourceType' => ['attrs' => ['resourceTypeGeneral' => 'Dataset'], 'value' => 'Dataset'],
        'relatedItems' => [
            'relatedItem' => [
                'attrs' => ['relatedItemType' => 'JournalArticle', 'relationType' => 'Cites'],
                'value' => [
                    'titles' => ['title' => ['value' => 'JSON-LD citation']],
                    'relatedItemIdentifier' => [
                        'attrs' => ['relatedItemIdentifierType' => 'DOI'],
                        'value' => '10.1234/jsonld-citation',
                    ],
                ],
            ],
        ],
    ];

    $this->postJson(route('editor.upload-json.preview'), [
        'file' => UploadedFile::fake()->createWithContent('citation.jsonld', json_encode($jsonLd, JSON_THROW_ON_ERROR)),
    ])->assertOk()
        ->assertJsonPath('metadata.relatedItems.0.related_item_type', 'JournalArticle')
        ->assertJsonPath('metadata.relatedItems.0.identifier', '10.1234/jsonld-citation');
});

test('editor upload previews preserve file validation and reject malformed data', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('editor.upload-xml.preview'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->postJson(route('editor.upload-json.preview'), [
        'file' => UploadedFile::fake()->createWithContent('invalid.json', '{invalid'),
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'json_parse_error');

    expect(Resource::count())->toBe(0);
});

test('editor upload previews require authentication', function () {
    $this->postJson(route('editor.upload-xml.preview'), ['file' => editorPreviewXml()])->assertUnauthorized();
    $this->postJson(route('editor.upload-json.preview'), [
        'file' => UploadedFile::fake()->createWithContent('import.json', dataCiteJson(minimalAttributes())),
    ])->assertUnauthorized();
});
