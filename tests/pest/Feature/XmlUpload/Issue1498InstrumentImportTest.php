<?php

declare(strict_types=1);

use App\Http\Controllers\EditorController;
use App\Models\PidSetting;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use App\Services\Pid4instVocabularyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
    PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PID4INST', 'is_active' => true]);
    $this->snapshot = file_get_contents(base_path('tests/pest/Fixtures/pid4inst/issue-1498.json'));
    Storage::put(Pid4instVocabularyService::FILE, $this->snapshot);
    $this->instruments = json_decode($this->snapshot, true)['data'];
});

function issue1498File(string $format): UploadedFile
{
    $xml = file_get_contents(base_path('tests/pest/dataset-examples/issue-1498-used-instruments.xml'));
    if ($format === 'xml') {
        return UploadedFile::fake()->createWithContent('issue1498.xml', $xml);
    }
    $records = json_decode(file_get_contents(base_path('tests/pest/Fixtures/pid4inst/issue-1498.json')), true)['data'];

    return UploadedFile::fake()->createWithContent('issue1498.json', dataCiteJson(minimalAttributes([
        'relatedIdentifiers' => array_map(fn ($record) => [
            'relatedIdentifier' => $record['pid'], 'relatedIdentifierType' => 'Handle', 'relationType' => 'IsCollectedBy',
        ], $records),
    ])));
}

it('resolves instrument names identically in XML and JSON previews without storing resources', function (string $format) {
    $metadata = $this->postJson('/editor/upload-'.$format.'/preview', ['file' => issue1498File($format)])->assertOk()->json('metadata');
    expect(Resource::count())->toBe(0)
        ->and(array_column($metadata['instruments'], 'name'))->toBe(array_column($this->instruments, 'name'))
        ->and(array_column($metadata['instruments'], 'pid'))->toBe(array_column($this->instruments, 'pid'))
        ->and(array_column(array_column($metadata['instruments'], 'resolution'), 'status'))->toBe(['matched', 'identifier_conflict', 'matched'])
        ->and($metadata['relatedWorks'])->toBe([]);
    Http::assertNothingSent();
})->with(['xml', 'json']);

it('stores resolved names and retains conflicts and identifiers through save reload and export', function (string $format) {
    $id = $this->postJson('/dashboard/upload-'.$format, ['file' => issue1498File($format)])->assertOk()->json('resourceId');
    $resource = Resource::findOrFail($id);
    expect($resource->instruments->pluck('instrument_name')->all())->toBe(array_column($this->instruments, 'name'));
    $editor = app(EditorDataTransformer::class)->transformInstruments($resource);
    $this->postJson('/editor/resources/draft', [
        'resourceId' => $id, 'titles' => [['title' => 'Instrument import regression', 'titleType' => 'MainTitle']], 'instruments' => $editor,
    ])->assertOk();
    $resource = $resource->fresh();
    $reloaded = app(EditorDataTransformer::class)->transformInstruments($resource);
    expect($reloaded)->toBe($editor)->and($resource->relatedIdentifiers)->toHaveCount(0);
    $json = app(DataCiteJsonExporter::class)->export($resource)['data']['attributes'];
    expect(array_column($json['relatedIdentifiers'], 'relatedIdentifier'))->toBe(array_column($this->instruments, 'pid'));
    $xml = app(DataCiteXmlExporter::class)->export($resource);
    foreach ($this->instruments as $instrument) {
        expect($xml)->toContain($instrument['pid']);
    }
    expect($xml)->not->toContain('resolution', 'lookupIdentifiers');
})->with(['xml', 'json']);

it('resolves names in legacy XML and JSON upload sessions', function (string $format) {
    $sessionKey = $format.'_upload_issue1498';
    session()->put($sessionKey, ['instruments' => array_map(fn ($record) => [
        'pid' => $record['pid'], 'pidType' => $record['pidType'], 'name' => $record['pid'],
    ], $this->instruments)]);
    $this->get('/editor?'.$format.'Session='.$sessionKey)->assertOk()->assertInertia(fn ($page) => $page
        ->component('editor')
        ->where('instruments.0.name', 'Green Roof Measurements MDR')
        ->where('instruments.1.pid', '21.11157/epmy8-2k126')
        ->where('instruments.1.resolution.status', 'identifier_conflict')
    );
    expect(Resource::count())->toBe(0);
    Http::assertNothingSent();
})->with(['xml', 'json']);

it('enriches existing placeholders without writing until the curator saves', function () {
    Storage::delete(Pid4instVocabularyService::FILE);
    $id = $this->postJson('/dashboard/upload-xml', ['file' => issue1498File('xml')])->assertOk()->json('resourceId');
    $resource = Resource::findOrFail($id);
    $before = $resource->instruments->toArray();
    $updated = $resource->updated_at;
    app(Pid4instVocabularyService::class)->replace($this->snapshot);
    Log::spy();
    $token = $this->get('/editor?resourceId='.$id)->assertOk()->inertiaProps('editorLoad.token');
    $instruments = $this->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $token)
        ->get('/editor?resourceId='.$id)->assertOk()->inertiaProps('instruments');
    expect(array_column($instruments, 'name'))->toBe(array_column($this->instruments, 'name'))
        ->and($resource->fresh()->instruments->toArray())->toBe($before)
        ->and($resource->fresh()->updated_at->equalTo($updated))->toBeTrue();
    Log::shouldNotHaveReceived('info', [Mockery::any(), Mockery::on(fn (array $context): bool => isset($context['activity']))]);
    $this->postJson('/editor/resources/draft', [
        'resourceId' => $id, 'titles' => [['title' => 'Existing instruments', 'titleType' => 'MainTitle']], 'instruments' => $instruments,
    ])->assertOk();
    expect($resource->fresh()->instruments->pluck('instrument_name')->all())->toBe(array_column($this->instruments, 'name'));
});

it('resolves through the authenticated endpoint without accepting client diagnostics', function () {
    $result = $this->postJson('/editor/instruments/resolve', ['instruments' => [
        ['pid' => '21.11157/epmy8-2k126', 'pidType' => 'Handle', 'name' => 'Curated name', 'resolution' => ['status' => 'matched']],
    ]])->assertOk()->json('instruments');
    expect($result[0]['name'])->toBe('Curated name')->and($result[0]['resolution']['status'])->toBe('identifier_conflict')
        ->and(Resource::count())->toBe(0);
    Http::assertNothingSent();
});

it('requires authentication for name resolution', function () {
    auth()->logout();
    $this->postJson('/editor/instruments/resolve', ['instruments' => []])->assertUnauthorized();
});

it('validates the instrument resolution input', function (array $input) {
    $this->postJson('/editor/instruments/resolve', $input)->assertUnprocessable();
})->with([
    [[]],
    [['instruments' => 'not an array']],
    [['instruments' => [['pid' => 'x', 'pidType' => 'Invalid', 'name' => 'Name']]]],
    [['instruments' => [['pid' => str_repeat('x', 513), 'pidType' => 'Handle', 'name' => 'Name']]]],
    [['instruments' => [['pid' => 'x', 'pidType' => 'Handle', 'name' => str_repeat('x', 1025)]]]],
]);
