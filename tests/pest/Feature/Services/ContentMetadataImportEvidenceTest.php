<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\TitleType;
use App\Models\User;
use App\Services\SizeFormat\ContentMetadataImportEvidenceService;
use App\Services\SizeFormat\ContentMetadataImportService;
use App\Services\SizeFormat\ResourceContentMetadataService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actor = User::factory()->curator()->create();
    $this->actingAs($this->actor);
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $this->draft = ['intent' => 'save-draft', 'titles' => [['title' => 'Imported content', 'titleType' => 'main-title']]];
    $this->evidence = app(ContentMetadataImportEvidenceService::class);
});

/** @return array<string, mixed> */
function importEvidenceRows(string $kind): array
{
    $importer = app(ContentMetadataImportService::class);

    return $kind === 'format'
        ? ['formats' => $importer->formats(['custom legacy format'])]
        : ['sizes' => array_map(fn (array $row): array => array_diff_key($row, ['editable' => true]),
            $importer->sizes([$kind === 'physical' ? '15 pages' : '2048 Primary Data Size [bytes]']))];
}

dataset('import evidence kinds', [
    'free-text format' => ['format', 'formats.0.value'],
    'physical size' => ['physical', 'sizes.0.unit'],
    'protected meaning' => ['meaning', 'sizes.0.type'],
]);

it('consumes each imported row only once when the first resource is saved', function (string $kind, string $error) {
    $metadata = $this->evidence->issue(importEvidenceRows($kind), $this->actor->id);
    $this->assertDatabaseCount('content_metadata_import_rows', 1);
    $response = $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
    $this->assertDatabaseCount('content_metadata_import_rows', 0);
    $before = Resource::count();

    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(Resource::count())->toBe($before);
    $resource = Resource::findOrFail($response->json('resource.id'));
    $state = app(ResourceContentMetadataService::class)->state($resource);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'formats' => $state['formats'],
        'sizes' => array_map(fn (array $row): array => array_intersect_key($row, array_flip(['id', 'numeric_value', 'unit', 'type'])), $state['sizes']),
        'contentMetadataFingerprint' => $state['fingerprint'],
    ])->assertOk();
})->with('import evidence kinds');

it('does not grant another user import exceptions or consume the original users evidence', function (string $kind, string $error) {
    $metadata = $this->evidence->issue(importEvidenceRows($kind), $this->actor->id);
    $this->actingAs(User::factory()->curator()->create())
        ->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('content_metadata_import_rows', 1);
    $this->actingAs($this->actor)->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
})->with('import evidence kinds');

it('rejects unused upload evidence on an existing resource', function (string $kind, string $error) {
    $metadata = $this->evidence->issue(importEvidenceRows($kind), $this->actor->id);
    $resource = Resource::factory()->create(['created_by_user_id' => $this->actor->id]);
    $fingerprint = app(ResourceContentMetadataService::class)->state($resource)['fingerprint'];
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $fingerprint,
    ])->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('content_metadata_import_rows', 1);
    expect($resource->formats()->count())->toBe(0)->and($resource->sizes()->count())->toBe(0);
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
})->with('import evidence kinds');

it('rolls back token consumption and earlier metadata writes when a later field fails', function () {
    $metadata = $this->evidence->issue(importEvidenceRows('format'), $this->actor->id);
    $before = Resource::count();
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata,
        'sizes' => [['numeric_value' => '2', 'unit' => 'invalid unit', 'type' => null]],
    ])->assertUnprocessable()->assertJsonValidationErrors('sizes.0.unit');
    expect(Resource::count())->toBe($before);
    $this->assertDatabaseMissing('formats', ['value' => 'custom legacy format']);
    $this->assertDatabaseCount('content_metadata_import_rows', 1);
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
    $this->assertDatabaseCount('content_metadata_import_rows', 0);
});

it('rejects altered values, units and meaning labels without burning the original evidence', function (string $kind, string $error, string $field, string $replacement) {
    $metadata = $this->evidence->issue(importEvidenceRows($kind), $this->actor->id);
    $altered = $metadata;
    $altered[$kind === 'format' ? 'formats' : 'sizes'][0][$field] = $replacement;
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$altered])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
})->with([
    ['format', 'formats.0.value', 'value', 'forged format'],
    ['physical', 'sizes.0.unit', 'unit', 'metres'],
    ['meaning', 'sizes.0.type', 'type', 'Forged Meaning'],
]);

it('does not accept the old unscoped encrypted token format', function (string $kind, string $error) {
    $metadata = importEvidenceRows($kind);
    $group = $kind === 'format' ? 'formats' : 'sizes';
    $metadata[$group][0]['importKey'] = Crypt::encryptString(json_encode([
        'kind' => $kind === 'format' ? 'format' : 'size', 'row' => $metadata[$group][0],
    ], JSON_THROW_ON_ERROR));
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with('import evidence kinds');

it('expires unused evidence and removes expired records during the next upload', function () {
    $metadata = $this->evidence->issue(importEvidenceRows('format'), $this->actor->id);
    $this->travel(24)->hours();
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])
        ->assertUnprocessable()->assertJsonValidationErrors('formats.0.value');
    $next = $this->evidence->issue(importEvidenceRows('format'), $this->actor->id);
    $this->assertDatabaseCount('content_metadata_import_rows', 1);
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$next])->assertCreated();
});

it('rejects reuse of a single size token within the same submission', function () {
    $metadata = $this->evidence->issue(importEvidenceRows('physical'), $this->actor->id);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => [$metadata['sizes'][0], $metadata['sizes'][0]]])
        ->assertUnprocessable()->assertJsonValidationErrors('sizes.1.importKey');
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$metadata])->assertCreated();
});

it('does not allow a format token to authorize a size meaning', function () {
    $formats = $this->evidence->issue(importEvidenceRows('format'), $this->actor->id);
    $sizes = importEvidenceRows('meaning')['sizes'];
    $sizes[0]['importKey'] = $formats['formats'][0]['importKey'];
    $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => $sizes])
        ->assertUnprocessable()->assertJsonValidationErrors('sizes.0.type');
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$formats])->assertCreated();
});

it('allows multiple uploads to contribute rows to one new resource', function () {
    $formats = $this->evidence->issue(importEvidenceRows('format'), $this->actor->id);
    $sizes = $this->evidence->issue(importEvidenceRows('physical'), $this->actor->id);
    $this->postJson('/editor/resources/draft', [...$this->draft, ...$formats, ...$sizes])->assertCreated();
    $this->assertDatabaseCount('content_metadata_import_rows', 0);
});

it('resolves UUID casing consistently without bypassing single-use checks', function () {
    $metadata = $this->evidence->issue(importEvidenceRows('physical'), $this->actor->id);
    $upper = $metadata['sizes'][0];
    $upper['importKey'] = strtoupper($upper['importKey']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => [$metadata['sizes'][0], $upper]])
        ->assertUnprocessable()->assertJsonValidationErrors('sizes.1.importKey');
    $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => [$upper]])->assertCreated();
});

it('does not query the evidence table for uploads without formats or sizes', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($this->evidence->issue(['titles' => []], $this->actor->id))->toBe(['titles' => []])
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});
