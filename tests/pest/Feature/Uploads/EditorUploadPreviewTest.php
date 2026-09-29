<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function editorPreviewXml(string $doi = '10.5880/preview'): UploadedFile
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">{$doi}</identifier>
    <titles><title>Imported title</title></titles>
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
