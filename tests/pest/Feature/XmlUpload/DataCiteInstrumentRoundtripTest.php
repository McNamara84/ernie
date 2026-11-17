<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\PidSetting;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Pid4instVocabularyService;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->actingAs(User::factory()->curator()->create());
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    $this->seed(TitleTypeSeeder::class);
    PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PIDINST', 'is_active' => true]);
    app(Pid4instVocabularyService::class)->replace(json_encode(['total' => 1, 'data' => [[
        'id' => 'datacite:10.1234/sensor', 'pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'DataCite sensor',
    ]]]));
});

it('saves selected DOI instruments and preserves their type through export and upload', function (string $format, bool $registry): void {
    $id = $this->postJson('/editor/resources/draft', [
        'titles' => [['title' => 'DOI sensor roundtrip', 'titleType' => 'MainTitle']],
        'year' => 2026,
        'instruments' => [['pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'Curated sensor']],
    ])->assertCreated()->json('resource.id');
    $resource = Resource::findOrFail($id);
    expect($resource->instruments[0]->instrument_pid_type)->toBe('DOI')
        ->and($resource->instruments[0]->instrument_name)->toBe('Curated sensor');
    $export = $format === 'xml' ? app(DataCiteXmlExporter::class)->export($resource) : json_encode(app(DataCiteJsonExporter::class)->export($resource));
    if (! $registry) {
        Storage::delete(Pid4instVocabularyService::FILE);
        Cache::flush();
    }
    $metadata = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => UploadedFile::fake()->createWithContent('roundtrip.'.$format, $export),
    ])->assertOk()->json('metadata');
    expect($metadata['instruments'])->toHaveCount(1)
        ->and($metadata['instruments'][0])->toMatchArray(['pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => $registry ? 'DataCite sensor' : '10.1234/sensor'])
        ->and($metadata['relatedWorks'])->toBe([]);
    Http::assertNothingSent();
})->with(['xml', 'json'])->with([true, false]);

it('keeps explicitly typed DOI references in related work despite a local instrument match', function (string $format, string $resourceType, bool $alias): void {
    if ($alias) {
        app(Pid4instVocabularyService::class)->replace(json_encode(['data' => [[
            'id' => 'b2inst-sensor', 'pid' => '21.1234/sensor', 'pidType' => 'Handle', 'name' => 'B2INST sensor',
            'lookupIdentifiers' => [['value' => '10.1234/sensor', 'type' => 'DOI', 'source' => 'metadata']],
        ]]]));
    }
    $source = Resource::factory()->create(['doi' => null]);
    $source->titles()->create(['value' => 'Explicit resource type regression', 'title_type_id' => TitleType::where('slug', 'MainTitle')->value('id')]);
    $source->relatedIdentifiers()->create([
        'identifier' => '10.1234/sensor',
        'identifier_type_id' => IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI'])->id,
        'relation_type_id' => RelationType::firstOrCreate(['slug' => 'IsCollectedBy'], ['name' => 'Is Collected By'])->id,
        'resource_type_general' => $resourceType, 'position' => 0,
    ]);
    $export = $format === 'xml' ? app(DataCiteXmlExporter::class)->export($source) : json_encode(app(DataCiteJsonExporter::class)->export($source));
    Http::fake(['*' => Http::response([], 404)]);

    $metadata = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => UploadedFile::fake()->createWithContent('explicit-type.'.$format, $export),
    ])->assertOk()->json('metadata');
    expect($metadata['instruments'])->toBe([])
        ->and($metadata['relatedWorks'])->toHaveCount(1)
        ->and($metadata['relatedWorks'][0])->toMatchArray([
            'identifier' => '10.1234/sensor', 'identifier_type' => 'DOI', 'relation_type' => 'IsCollectedBy',
        ]);

    $id = $this->postJson('/dashboard/upload-'.$format, [
        'file' => UploadedFile::fake()->createWithContent('explicit-type.'.$format, $export),
    ])->assertOk()->json('resourceId');
    $imported = Resource::findOrFail($id);
    expect($imported->instruments)->toHaveCount(0)->and($imported->relatedIdentifiers)->toHaveCount(1);
    $reexported = app(DataCiteJsonExporter::class)->export($imported)['data']['attributes']['relatedIdentifiers'];
    expect($reexported)->toHaveCount(1)
        ->and($reexported[0]['relatedIdentifier'])->toBe('10.1234/sensor')
        ->and($reexported[0]['relationType'])->toBe('IsCollectedBy')
        ->and($reexported[0]['resourceTypeGeneral'] ?? null)->not->toBe('Instrument');
    expect(app(DataCiteXmlExporter::class)->export($imported))->not->toContain('resourceTypeGeneral="Instrument"');
})->with(['xml', 'json'])->with(['Dataset', 'Software'])->with([false, true]);

it('recognizes unmarked legacy DOI references only when found locally', function (string $format, bool $known): void {
    $doi = $known ? '10.1234/sensor' : '10.1234/unrelated';
    $resource = Resource::factory()->create();
    $resource->titles()->create(['value' => 'Legacy instrument test', 'title_type_id' => TitleType::where('slug', 'MainTitle')->value('id')]);
    $resource->instruments()->create(['instrument_pid' => $doi, 'instrument_pid_type' => 'DOI', 'instrument_name' => 'Sensor']);
    if ($format === 'xml') {
        $export = str_replace(' resourceTypeGeneral="Instrument"', '', app(DataCiteXmlExporter::class)->export($resource));
    } else {
        $payload = app(DataCiteJsonExporter::class)->export($resource);
        unset($payload['data']['attributes']['relatedIdentifiers'][0]['resourceTypeGeneral']);
        $export = json_encode($payload);
    }
    // Existing citation resolution is permitted for ordinary related works only.
    Http::fake(['*' => Http::response([], 404)]);
    $metadata = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => UploadedFile::fake()->createWithContent('legacy.'.$format, $export),
    ])->assertOk()->json('metadata');
    expect($metadata['instruments'])->toHaveCount($known ? 1 : 0)
        ->and($metadata['relatedWorks'])->toHaveCount($known ? 0 : 1);
    if ($known) {
        Http::assertNothingSent();
    }
})->with(['xml', 'json'])->with([true, false]);
