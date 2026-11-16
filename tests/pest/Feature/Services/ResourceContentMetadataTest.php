<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use App\Services\SizeFormat\ContentMetadataImportService;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\MimeTypeRegistry;
use App\Services\SizeFormat\ResourceContentMetadataService;

beforeEach(function () {
    $this->actingAs(User::factory()->curator()->create());
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $this->datasetType = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset']);
    $this->contentMetadata = app(ResourceContentMetadataService::class);
    $this->draft = ['intent' => 'save-draft', 'titles' => [['title' => 'Content metadata', 'titleType' => 'main-title']], 'resourceType' => $this->datasetType->id];
});

it('creates independent resource formats and sizes without a landing page for each save intent', function (string $intent) {
    $response = $this->postJson('/editor/resources/draft', [...$this->draft, 'intent' => $intent,
        'formats' => [['value' => 'text/csv'], ['value' => 'application/zip']],
        'sizes' => [['numeric_value' => '1,5', 'unit' => 'GiB', 'type' => null]],
    ])->assertCreated()->assertJsonCount(2, 'contentMetadata.formats')->assertJsonPath('contentMetadata.sizes.0.numeric_value', '1.5000');
    $resource = Resource::findOrFail($response->json('resource.id'));
    expect($resource->landingPage)->toBeNull()
        ->and($resource->formats()->count())->toBe(2)
        ->and($resource->sizes()->sole()->unit)->toBe('GiB')
        ->and($this->contentMetadata->state($resource)['fingerprint'])->toBe($response->json('contentMetadata.fingerprint'));
    expect(app(EditorDataTransformer::class)->transformResource($resource)['contentMetadata'])->toBe($response->json('contentMetadata'));
})->with(['save-draft', 'autosave', 'landing-page-preview']);

it('preserves IDs and meaning labels when editing assistant metadata', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $size = $resource->sizes()->create(['numeric_value' => '2048', 'unit' => 'bytes', 'type' => 'Uncompressed Primary Data Size']);
    $page = LandingPage::factory()->for($resource)->draft()->create(['ftp_url' => 'https://example.org/data.zip', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id]);
    $state = $this->contentMetadata->state($resource);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $state['fingerprint'],
        'formats' => [['id' => $format->id, 'value' => 'text/csv']],
        'sizes' => [['id' => $size->id, 'numeric_value' => '4096', 'unit' => 'bytes', 'type' => $size->type]],
    ])->assertOk();
    expect($format->fresh()->value)->toBe('text/csv')->and($size->fresh()->numeric_value)->toBe('4096.0000')
        ->and($size->fresh()->type)->toBe('Uncompressed Primary Data Size')
        ->and($page->fresh()->ftp_format_id)->toBe($format->id)->and($page->fresh()->ftp_size_id)->toBe($size->id);
});

it('preserves omitted metadata and protected sizes but clears explicitly removed digital entries', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $format = $resource->formats()->create(['value' => 'application/x-netcdf']);
    $digital = $resource->sizes()->create(['numeric_value' => '2', 'unit' => 'GB']);
    $physical = $resource->sizes()->create(['numeric_value' => '15', 'unit' => 'pages']);
    $raw = $resource->sizes()->create(['unit' => 'original unstructured size']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id])->assertOk();
    expect($format->fresh())->not->toBeNull()->and($digital->fresh())->not->toBeNull();
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource->fresh())['fingerprint'], 'formats' => [], 'sizes' => [],
    ])->assertOk();
    expect($format->fresh())->toBeNull()->and($digital->fresh())->toBeNull()
        ->and($physical->fresh())->not->toBeNull()->and($raw->fresh())->not->toBeNull();
});

it('rejects invalid digital sizes without partial metadata writes', function (string $value, ?string $unit, string $field) {
    $this->postJson('/editor/resources/draft', [...$this->draft,
        'formats' => [['value' => 'text/csv']], 'sizes' => [['numeric_value' => $value, 'unit' => $unit, 'type' => null]],
    ])->assertUnprocessable()->assertJsonValidationErrors("sizes.0.{$field}");
    expect(Resource::query()->count())->toBe(0);
})->with([
    ['-1', 'MB', 'numeric_value'], ['0', 'B', 'numeric_value'], ['1e3', 'B', 'numeric_value'],
    ['1.23456', 'GB', 'numeric_value'], ['10000000000000000', 'B', 'numeric_value'], ['1,234.5', 'B', 'numeric_value'],
    ['2', null, 'unit'], ['2', 'pages', 'unit'],
]);

it('requires an IANA type for new formats but retains existing non-IANA values', function () {
    $this->postJson('/editor/resources/draft', [...$this->draft, 'formats' => [['value' => 'application/x-custom-not-registered']]])
        ->assertUnprocessable()->assertJsonValidationErrors('formats.0.value');
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $format = $resource->formats()->create(['value' => 'application/x-netcdf']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource)['fingerprint'],
        'formats' => [['id' => $format->id, 'value' => $format->value]],
    ])->assertOk();
    expect($format->fresh()->value)->toBe('application/x-netcdf');
});

it('validates and exports the same resource metadata through the complete editor save', function () {
    $right = Right::firstOrCreate(['identifier' => 'CC-BY-4.0'], ['name' => 'Creative Commons Attribution 4.0', 'uri' => 'https://creativecommons.org/licenses/by/4.0/']);
    DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract']);
    $response = $this->postJson('/editor/resources', [
        'year' => 2026, 'resourceType' => $this->datasetType->id, 'accessLevel' => 'open',
        'datacenter_id' => Datacenter::factory()->create()->id,
        'titles' => $this->draft['titles'], 'licenses' => [$right->identifier],
        'authors' => [['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Editor', 'position' => 0]],
        'descriptions' => [['descriptionType' => 'abstract', 'description' => 'Resource formats and size metadata.']],
        'formats' => [['value' => 'text/csv']], 'sizes' => [['numeric_value' => '1,5', 'unit' => 'GiB', 'type' => null]],
    ])->assertCreated();
    $resource = Resource::findOrFail($response->json('resource.id'));
    $json = app(DataCiteJsonExporter::class)->export($resource);
    $xml = app(DataCiteXmlExporter::class)->export($resource);
    expect($json['data']['attributes']['formats'])->toBe(['text/csv'])
        ->and($json['data']['attributes']['sizes'])->toBe(['1.5 GiB'])
        ->and($xml)->toContain('<format>text/csv</format>', '<size>1.5 GiB</size>');
});

it('rejects duplicate new formats and editing protected physical sizes', function () {
    $this->postJson('/editor/resources/draft', [...$this->draft, 'formats' => [['value' => 'text/csv'], ['value' => 'TEXT/CSV']]])
        ->assertUnprocessable()->assertJsonValidationErrors('formats.1.value');
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $size = $resource->sizes()->create(['numeric_value' => '15', 'unit' => 'pages']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource)['fingerprint'],
        'sizes' => [['id' => $size->id, 'numeric_value' => '15.0000', 'unit' => 'MB', 'type' => null]],
    ])->assertUnprocessable()->assertJsonValidationErrors('sizes.0.numeric_value');
    expect($size->fresh()->unit)->toBe('pages');
});

it('preserves imported meaning labels when a new digital value is edited before saving', function () {
    $row = app(ContentMetadataImportService::class)->sizes(['2048 Uncompressed Primary Data Size [bytes]'])[0];
    unset($row['editable']);
    $row['numeric_value'] = '4096';
    $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => [$row]])->assertCreated()
        ->assertJsonPath('contentMetadata.sizes.0.numeric_value', '4096.0000')
        ->assertJsonPath('contentMetadata.sizes.0.type', 'Uncompressed Primary Data Size');
});

it('retains blank and whitespace-bearing legacy formats while adding a new IANA format', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $blank = $resource->formats()->create(['value' => '']);
    $legacy = $resource->formats()->create(['value' => '  .ZIP  ']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource)['fingerprint'],
        'formats' => [['id' => $blank->id, 'value' => ''], ['id' => $legacy->id, 'value' => $legacy->value], ['value' => 'text/csv']],
    ])->assertOk();
    expect($blank->fresh()->value)->toBe('')->and($legacy->fresh()->value)->toBe('  .ZIP  ')->and($resource->formats()->count())->toBe(3);
});

it('coalesces equivalent imported formats while retaining other formats', function () {
    $rows = app(ContentMetadataImportService::class)->formats(['CSV', 'text/csv', 'TEXT/CSV', 'application/zip']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'formats' => $rows])->assertCreated()
        ->assertJsonCount(2, 'contentMetadata.formats');
});

it('does not let signed import evidence replace the meaning of an existing size', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $size = $resource->sizes()->create(['numeric_value' => '2048', 'unit' => 'bytes', 'type' => 'Primary Data Size']);
    $row = app(ContentMetadataImportService::class)->sizes(['4096 Uncompressed Primary Data Size [bytes]'])[0];
    unset($row['editable']);
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource)['fingerprint'],
        'sizes' => [['id' => $size->id, ...$row]],
    ])->assertUnprocessable()->assertJsonValidationErrors('sizes.0.type');
    expect($size->fresh()->type)->toBe('Primary Data Size');
});

it('does not accept foreign IDs, duplicate IDs, or forged size meanings', function (string $kind) {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $other = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $size = $resource->sizes()->create(['numeric_value' => '2', 'unit' => 'MB', 'type' => 'Primary Data Size']);
    $foreign = $other->formats()->create(['value' => 'text/csv']);
    $extra = match ($kind) {
        'foreign' => ['formats' => [['id' => $foreign->id, 'value' => 'text/csv']]],
        'duplicate' => ['sizes' => array_fill(0, 2, ['id' => $size->id, 'numeric_value' => '2.0000', 'unit' => 'MB', 'type' => $size->type])],
        'meaning' => ['sizes' => [['id' => $size->id, 'numeric_value' => '2', 'unit' => 'MB', 'type' => 'Fake meaning']]],
    };
    $this->postJson('/editor/resources/draft', [...$this->draft, 'resourceId' => $resource->id,
        'contentMetadataFingerprint' => $this->contentMetadata->state($resource)['fingerprint'], ...$extra,
    ])->assertUnprocessable();
    expect($size->fresh()->type)->toBe('Primary Data Size')->and($foreign->fresh()->resource_id)->toBe($other->id);
})->with(['foreign', 'duplicate', 'meaning']);

it('rejects stale editor changes while allowing an unrelated save without overwriting assistance', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $fingerprint = $this->contentMetadata->state($resource)['fingerprint'];
    $resource->formats()->create(['value' => 'text/csv']);
    $payload = [...$this->draft, 'resourceId' => $resource->id, 'contentMetadataFingerprint' => $fingerprint, 'formats' => []];
    $this->postJson('/editor/resources/draft', $payload)->assertUnprocessable()->assertJsonValidationErrors('contentMetadataFingerprint');
    $this->postJson('/editor/resources/draft', [...$payload, 'contentMetadataChanged' => false])->assertOk()->assertJsonPath('contentMetadata.formats.0.value', 'text/csv');
    expect($resource->formats()->count())->toBe(1);
});

it('preserves signed imported formats and labeled sizes and rejects altered import evidence', function () {
    $importer = app(ContentMetadataImportService::class);
    $formats = $importer->formats(['application/x-custom-imported']);
    $sizes = $importer->sizes(['2048 Uncompressed Primary Data Size [bytes]', '15 pages', 'unknown size']);
    $response = $this->postJson('/editor/resources/draft', [...$this->draft, 'formats' => $formats, 'sizes' => array_map(fn ($row) => array_diff_key($row, ['editable' => true]), $sizes)])
        ->assertCreated()->assertJsonPath('contentMetadata.sizes.0.type', 'Uncompressed Primary Data Size');
    $resource = Resource::findOrFail($response->json('resource.id'));
    expect($resource->sizes()->count())->toBe(3)->and($resource->sizes()->first()->export_string)->toBe('2048 Uncompressed Primary Data Size [bytes]');
    $formats[0]['value'] = 'application/x-forged-import';
    $this->postJson('/editor/resources/draft', [...$this->draft, 'formats' => $formats])->assertUnprocessable()->assertJsonValidationErrors('formats.0.value');
});

it('preserves exact large decimal sizes and distinguishes decimal from binary units', function () {
    $response = $this->postJson('/editor/resources/draft', [...$this->draft, 'sizes' => [
        ['numeric_value' => '9007199254740993', 'unit' => 'B', 'type' => null],
        ['numeric_value' => '2', 'unit' => 'GB', 'type' => null], ['numeric_value' => '2', 'unit' => 'GiB', 'type' => null],
    ]])->assertCreated();
    $sizes = Resource::findOrFail($response->json('resource.id'))->sizes;
    expect($sizes[0]->numeric_value)->toBe('9007199254740993.0000')
        ->and(app(DigitalContentSizeService::class)->toBytes($sizes[1]))->toBe('2000000000')
        ->and(app(DigitalContentSizeService::class)->toBytes($sizes[2]))->toBe('2147483648');
});

it('ships the complete offline IANA catalog with all registered categories', function () {
    $catalog = json_decode(file_get_contents(resource_path('data/iana-media-types.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(count($catalog['types']))->toBeGreaterThan(2300)->and($catalog['source_sha256'])->toHaveLength(64);
    $registry = app(MimeTypeRegistry::class);
    foreach (['application/zip', 'audio/mpeg', 'font/woff2', 'haptics/ivs', 'image/png', 'message/rfc822', 'model/gltf+json', 'multipart/form-data', 'text/csv', 'video/mp4'] as $type) {
        expect($registry->contains($type))->toBeTrue($type);
    }
    expect($registry->contains('application/x-not-registered'))->toBeFalse();
});

it('parses IANA names including deprecated entries without requiring registration templates', function () {
    $catalog = MimeTypeRegistry::fromXml('<registry xmlns="http://www.iana.org/assignments" id="media-types"><updated>2026-11-10</updated><registry id="application"><record><name>old - deprecated</name></record><record><name>vnd.test+json</name></record></registry></registry>');
    expect($catalog['types'])->toBe(['application/old', 'application/vnd.test+json']);
});

it('rejects invalid or unsafe registry XML', function (string $xml) {
    expect(fn () => MimeTypeRegistry::fromXml($xml))->toThrow(InvalidArgumentException::class);
})->with(['<invalid>', '<registry/>', '<!DOCTYPE registry><registry/>', '<registry xmlns="http://www.iana.org/assignments" id="media-types"/>']);
