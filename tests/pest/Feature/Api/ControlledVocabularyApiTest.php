<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Http\Controllers\VocabularyController;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use App\Services\MslVocabularyService;
use App\Services\PublicVocabularySchemaService;
use App\Services\PublicVocabularySnapshotService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use function Pest\Laravel\getJson;

covers(VocabularyController::class, PublicVocabularySchemaService::class, PublicVocabularySnapshotService::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'vocabulary-key']);
    Storage::fake();
    Cache::flush();
});

/** @return array<string, array{string, string, string}> Settings key, local fixture path and public schema by published URL slug. */
function publicVocabularyContract(): array
{
    return [
        'gcmd-science-keywords' => ['science_keywords', 'gcmd-science-keywords.json', 'GcmdScienceKeywords'],
        'gcmd-platforms' => ['platforms', 'gcmd-platforms.json', 'GcmdPlatforms'],
        'gcmd-instruments' => ['instruments', 'gcmd-instruments.json', 'GcmdInstruments'],
        'msl' => ['msl_keywords', 'msl-vocabulary.json', 'MslKeywords'],
        'msl-laboratories' => ['msl_laboratories', 'msl-laboratories.json', 'MslLaboratoriesResponse'],
        'chronostrat-timescale' => ['chronostratigraphy', 'chronostrat-timescale.json', 'ChronostratTimescale'],
        'gemet' => ['gemet', 'gemet-thesaurus.json', 'GemetThesaurus'],
        'analytical-methods' => ['analytical_methods', 'analytical-methods.json', 'AnalyticalMethodsVocabulary'],
        'euroscivoc' => ['euroscivoc', 'euroscivoc.json', 'EuroSciVocVocabulary'],
        'cgi-simple-lithology' => ['simple_lithology', 'cgi-simple-lithology.json', 'CgiSimpleLithologyVocabulary'],
        'pid4inst-instruments' => ['pid4inst', 'pid4inst-instruments.json', 'Pid4instInstruments'],
        'raid-projects' => ['raid', 'raid/raid-projects.json', 'RaidProjects'],
        'ror-affiliations' => ['ror', 'ror/ror-affiliations.json', 'RorAffiliations'],
    ];
}

it('returns the complete editor defaults even when no settings or files exist', function (): void {
    ThesaurusSetting::query()->delete();
    PidSetting::query()->delete();
    $expected = array_fill_keys(array_keys(publicVocabularyContract()), true);
    $expected['cgi-simple-lithology'] = false;

    foreach (['elmo', 'elmo-msl'] as $editor) {
        getJson("/api/v1/{$editor}/vocabularies/visibility", ['Authorization' => 'Bearer vocabulary-key'])
            ->assertOk()->assertExactJson($expected);
    }
    expect(ThesaurusSetting::count())->toBe(0)->and(PidSetting::count())->toBe(0);

    // Version 1.1 treats a missing setting as active and proceeds to the file lookup.
    getJson('/api/v1/vocabularies/cgi-simple-lithology', ['X-API-Key' => 'vocabulary-key'])->assertNotFound()
        ->assertJsonPath('error', 'Vocabulary file not found. Please run: php artisan get-cgi-simple-lithology');
    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'vocabulary-key'])->assertNotFound()
        ->assertJsonPath('error', 'Vocabulary file not found. Please run: php artisan get-ror-ids');
});

it('reports each editor setting immediately, including enabled sources with missing files', function (): void {
    Storage::shouldReceive('exists', 'get')->never();
    $contract = publicVocabularyContract();
    $allHidden = array_fill_keys(array_keys($contract), false);
    $allVisible = array_fill_keys(array_keys($contract), true);
    $settings = [];
    foreach ($contract as $slug => [$type]) {
        $model = in_array($type, ['pid4inst', 'raid', 'ror'], true) ? PidSetting::class : ThesaurusSetting::class;
        $settings[$slug] = $model::updateOrCreate(['type' => $type], [
            'display_name' => $slug, 'is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => true,
        ]);
    }

    foreach ($settings as $slug => $setting) {
        $setting->update(['is_elmo_active' => true, 'is_elmo_msl_active' => false]);
        getJson('/api/v1/elmo/vocabularies/visibility?editor=elmo-msl', ['X-API-Key' => 'vocabulary-key'])
            ->assertOk()->assertExactJson(array_replace($allHidden, [$slug => true]));
        getJson('/api/v1/elmo-msl/vocabularies/visibility?editor=elmo', ['Authorization' => 'Bearer vocabulary-key'])
            ->assertOk()->assertExactJson(array_replace($allVisible, [$slug => false]));
        $setting->update(['is_elmo_active' => false, 'is_elmo_msl_active' => true]);
    }
});

it('distinguishes a missing snapshot from a corrupt one for every published slug', function (): void {
    foreach (publicVocabularyContract() as $slug => [, $file]) {
        $url = '/api/v1/vocabularies/data/'.$slug;
        getJson($url, ['X-API-Key' => 'vocabulary-key'])->assertNotFound()->assertJsonStructure(['error']);
        Storage::put($file, '{broken');
        getJson($url, ['Authorization' => 'Bearer vocabulary-key'])->assertStatus(500)->assertJsonStructure(['error']);
    }
    ThesaurusSetting::whereIn('type', ['msl_laboratories', 'simple_lithology'])->update(['is_elmo_active' => true]);
    getJson('/api/v1/elmo/vocabularies/visibility', ['X-API-Key' => 'vocabulary-key'])
        ->assertOk()->assertJsonPath('msl-laboratories', true)->assertJsonPath('cgi-simple-lithology', true);
    getJson('/api/v1/elmo/vocabularies/thesauri-availability', ['X-API-Key' => 'vocabulary-key'])
        ->assertOk()->assertJsonPath('msl_laboratories.available', false)->assertJsonPath('simple_lithology.available', false);
});

it('requires a header credential for each new operation', function (string $path): void {
    Storage::put('msl-vocabulary.json', '[]');
    getJson($path)->assertUnauthorized();
    getJson($path, ['X-API-Key' => 'incorrect'])->assertUnauthorized();
    getJson($path.'?api_key=vocabulary-key')->assertUnauthorized();
    getJson($path, ['Authorization' => 'Bearer vocabulary-key'])->assertOk();
    getJson($path, ['X-API-Key' => 'vocabulary-key'])->assertOk();
    config(['services.ernie.api_key' => '']);
    getJson($path, ['X-API-Key' => 'vocabulary-key'])->assertUnauthorized();
})->with([
    '/api/v1/elmo/vocabularies/visibility',
    '/api/v1/elmo-msl/vocabularies/visibility',
    '/api/v1/vocabularies/data/msl',
]);

it('rejects an empty JSON object for every vocabulary', function (string $slug, string $file): void {
    Storage::put($file, '{}');
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertInternalServerError()->assertJsonStructure(['error']);
})->with(fn () => collect(publicVocabularyContract())->mapWithKeys(
    fn (array $entry, string $slug) => [$slug => [$slug, $entry[1]]],
)->all());

it('rejects malformed wrapped and legacy ROR snapshots', function (string $json): void {
    Storage::put('ror/ror-affiliations.json', $json);
    getJson('/api/v1/vocabularies/data/ror-affiliations', ['X-API-Key' => 'vocabulary-key'])
        ->assertInternalServerError()->assertJsonStructure(['error']);
})->with([
    'missing metadata' => '{"data":[]}',
    'invalid update time' => '{"lastUpdated":"yesterday","total":0,"data":[]}',
    'invalid total type' => '{"lastUpdated":"2026-11-01T00:00:00Z","total":"0","data":[]}',
    'inconsistent total' => '{"lastUpdated":"2026-11-01T00:00:00Z","total":4,"data":[]}',
    'scalar data' => '{"lastUpdated":"2026-11-01T00:00:00Z","total":0,"data":"not-an-array"}',
    'object data' => '{"lastUpdated":"2026-11-01T00:00:00Z","total":0,"data":{}}',
    'invalid wrapped record' => '{"lastUpdated":"2026-11-01T00:00:00Z","total":1,"data":[{"prefLabel":"GFZ","rorId":false,"otherLabel":[]}]}',
    'missing legacy record field' => '[{"prefLabel":"GFZ","rorId":"https://ror.org/04z8jg394"}]',
    'invalid alias' => '[{"prefLabel":"GFZ","rorId":"https://ror.org/04z8jg394","otherLabel":[null]}]',
    'object aliases' => '[{"prefLabel":"GFZ","rorId":"https://ror.org/04z8jg394","otherLabel":{}}]',
]);

it('validates the schema for the requested slug including nested records', function (string $slug, string $file, string $json): void {
    Storage::put($file, $json);
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertInternalServerError()->assertJsonStructure(['error']);
})->with([
    'GCMD data must be a list' => ['gcmd-platforms', 'gcmd-platforms.json', '{"data":{}}'],
    'MSL wrapped data must be a list' => ['msl', 'msl-vocabulary.json', '{"data":{}}'],
    'EuroSciVoc requires its timestamp' => ['euroscivoc', 'euroscivoc.json', '{"data":[]}'],
    'RAiD requires its total' => ['raid-projects', 'raid/raid-projects.json', '{"lastUpdated":"2026-11-01T00:00:00Z","data":[]}'],
    'RAiD requires project fields' => ['raid-projects', 'raid/raid-projects.json', '{"lastUpdated":"2026-11-01T00:00:00Z","total":1,"data":[{"id":"project"}]}'],
    'PID4INST owners must be a list' => ['pid4inst-instruments', 'pid4inst-instruments.json', '{"lastUpdated":"2026-11-01T00:00:00Z","total":1,"data":[{"id":"a","pid":"p","pidType":"DOI","name":"n","owners":{}}]}'],
    'MSL laboratory data must be a list' => ['msl-laboratories', 'msl-laboratories.json', '{"version":"1.2","lastUpdated":"2026-11-01T00:00:00Z","total":0,"source":{"repository":"msl","ref":"main","path":"labs.json","sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"data":{}}'],
]);

it('rejects an unknown slug and internal setting keys', function (string $slug): void {
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertNotFound()->assertExactJson(['error' => 'Unknown vocabulary.']);
})->with(['unknown', 'science_keywords', 'ror']);

it('reports unreadable files through the shared data endpoint', function (string $slug, string $file): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::shouldReceive('disk')->andReturn($disk);
    $disk->shouldReceive('exists')->with($file)->once()->andReturn(true);
    $disk->shouldReceive($slug === 'ror-affiliations' ? 'readStream' : 'get')->with($file)->once()->andReturn(null);
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertStatus(500)->assertExactJson(['error' => 'Failed to read vocabulary file.']);
})->with([
    ['gcmd-platforms', 'gcmd-platforms.json'],
    ['ror-affiliations', 'ror/ror-affiliations.json'],
]);

it('reports a storage failure separately from a missing vocabulary', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::shouldReceive('disk')->andReturn($disk);
    $disk->shouldReceive('exists')->with('gcmd-platforms.json')->once()
        ->andThrow(UnableToCheckFileExistence::forLocation('gcmd-platforms.json'));
    getJson('/api/v1/vocabularies/data/gcmd-platforms', ['X-API-Key' => 'vocabulary-key'])
        ->assertStatus(500)->assertExactJson(['error' => 'Failed to read vocabulary file.']);
});

it('returns a structured error when the published vocabulary schema cannot be read', function (bool $readWarning): void {
    Storage::put('msl-vocabulary.json', '[]');
    File::partialMock()->shouldReceive('hash')->once()->with(resource_path('data/openapi.json'), 'xxh128')
        ->andReturnUsing(fn () => $readWarning ? throw new ErrorException('Failed to open stream') : false);
    getJson('/api/v1/vocabularies/data/msl', ['X-API-Key' => 'vocabulary-key'])
        ->assertInternalServerError()->assertExactJson(['error' => 'Failed to read vocabulary file.']);
})->with(['unavailable hash' => false, 'read warning' => true]);

it('revalidates an unchanged snapshot when its published schema changes', function (): void {
    $schema = $this->partialMock(PublicVocabularySchemaService::class);
    $schema->shouldReceive('revision')->times(3)->andReturn('initial', 'initial', 'updated');
    $schema->shouldReceive('validate')->twice()->passthru();
    Storage::put('msl-vocabulary.json', '[]');

    for ($request = 0; $request < 3; $request++) {
        getJson('/api/v1/vocabularies/data/msl', ['X-API-Key' => 'vocabulary-key'])
            ->assertOk()->assertExactJson([]);
    }
});

it('invalidates a public snapshot entry in the same store and tag namespace used by the API', function (string $suffix, string $source, array $tags): void {
    config(['cache.stores.file.path' => Storage::path('snapshot-cache')]);
    $slug = explode(':', $suffix, 2)[0];
    Storage::put(publicVocabularyContract()[$slug][1], $source);
    $this->travel(5)->seconds(); // Allow the local ROR file to use its stable-file cache entry.
    withNativeFileStreams(fn () => getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key']))->assertOk();

    $pattern = CacheKey::PUBLIC_VOCABULARY_SNAPSHOT;
    $key = $pattern->key($suffix);
    $cache = $tags === [] ? Cache::store('file') : Cache::tags($tags);
    expect($cache->has($key))->toBeTrue()
        ->and($pattern->forget($suffix))->toBeTrue()
        ->and($cache->has($key))->toBeFalse();
})->with([
    'vocabulary marker' => ['msl', '[]', ['vocabularies']],
    'ROR content marker' => ['ror-affiliations', '[]', ['vocabularies', 'ror']],
    'ROR file marker' => ['ror-affiliations:file', '[]', ['vocabularies', 'ror']],
    'public instruments on disk' => ['pid4inst-instruments', '{"lastUpdated":"2026-11-01T00:00:00Z","total":0,"data":[]}', []],
    'public laboratories on disk' => ['msl-laboratories', '{"version":"1.2","lastUpdated":"2026-11-01T00:00:00Z","total":0,"source":{"repository":"msl","ref":"main","path":"labs.json","sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"data":[]}', []],
]);

it('rejects scalar vocabulary data', function (string $slug, string $file): void {
    Storage::put($file, 'false');
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertStatus(500)->assertJsonStructure(['error']);
})->with([['msl', 'msl-vocabulary.json'], ['ror-affiliations', 'ror/ror-affiliations.json']]);

it('wraps a legacy ROR list using its file timestamp and preserves the 1.1 response', function (): void {
    $file = 'ror/ror-affiliations.json';
    $records = [['prefLabel' => 'GFZ', 'rorId' => 'https://ror.org/04z8jg394', 'otherLabel' => ['GFZ Potsdam']]];
    Storage::put($file, " \n".json_encode($records, JSON_THROW_ON_ERROR));

    getJson('/api/v1/vocabularies/data/ror-affiliations', ['X-API-Key' => 'vocabulary-key'])->assertOk()->assertExactJson([
        'lastUpdated' => gmdate(DATE_ATOM, Storage::lastModified($file)), 'total' => 1, 'data' => $records,
    ]);
    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'vocabulary-key'])->assertOk()->assertExactJson($records);
});

it('streams each ROR revision after one validation and keeps an in-flight download on its original file', function (bool $wrapped): void {
    $this->partialMock(PublicVocabularySchemaService::class)
        ->shouldReceive('validate')->twice()->passthru();
    $url = '/api/v1/vocabularies/data/ror-affiliations';
    $headers = ['X-API-Key' => 'vocabulary-key'];
    $file = 'ror/ror-affiliations.json';
    $mtime = 1700000000;
    $old = ['lastUpdated' => gmdate(DATE_ATOM, $mtime), 'total' => 1, 'data' => [[
        'prefLabel' => 'Old', 'rorId' => 'https://ror.org/04z8jg394', 'otherLabel' => [],
    ]]];
    $new = $old;
    $new['data'][0]['prefLabel'] = 'New';
    Storage::put($file, json_encode($wrapped ? $old : $old['data'], JSON_THROW_ON_ERROR));
    touch(Storage::path($file), $mtime);

    getJson($url, $headers)->assertOk()->assertStreamed()->assertHeader('Content-Type', 'application/json')->assertExactJson($old);
    $inFlight = getJson($url, $headers)->assertOk()->assertStreamed();

    Storage::put($file.'.new', json_encode($wrapped ? $new : $new['data'], JSON_THROW_ON_ERROR));
    touch(Storage::path($file.'.new'), $mtime);
    rename(Storage::path($file.'.new'), Storage::path($file));
    getJson($url, $headers)->assertOk()->assertExactJson($new);
    $inFlight->assertExactJson($old);
    Storage::delete($file);
    getJson($url, $headers)->assertNotFound();
})->with(['legacy list' => false, 'wrapped registry' => true]);

it('does not read the stable local ROR file until the warm response body is sent', function (): void {
    $file = 'ror/ror-affiliations.json';
    $json = '{"lastUpdated":"2026-11-01T00:00:00Z","total":0,"data":[]}';
    Storage::put($file, $json);
    $path = Storage::path($file);
    $this->travel(5)->seconds();
    $counter = (object) ['bytes' => 0];
    stream_filter_register('ernie.test.ror-read-count', RorReadCountingFilter::class);
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::shouldReceive('disk')->andReturn($disk);
    $disk->shouldReceive('exists')->with($file)->andReturn(true);
    $disk->shouldReceive('readStream')->with($file)->twice()->andReturnUsing(static function () use ($path, $counter) {
        $handle = withNativeFileStreams(static fn () => fopen($path, 'rb'));
        stream_filter_append($handle, 'ernie.test.ror-read-count', STREAM_FILTER_READ, $counter);

        return $handle;
    });

    $url = '/api/v1/vocabularies/data/ror-affiliations';
    $headers = ['X-API-Key' => 'vocabulary-key'];
    getJson($url, $headers)->assertOk()->assertStreamedContent($json);
    $counter->bytes = 0;
    $warm = getJson($url, $headers)->assertOk()->assertStreamed();
    expect($counter->bytes)->toBe(0);
    $warm->assertStreamedContent($json);
    expect($counter->bytes)->toBe(strlen($json));
});

it('serves a validated ROR list from a non-seekable storage stream', function (): void {
    [$input, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($writer, '[]');
    fclose($writer);
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::shouldReceive('disk')->andReturn($disk);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('readStream')->once()->andReturn($input);
    $disk->shouldReceive('lastModified')->andReturn(1700000000);
    getJson('/api/v1/vocabularies/data/ror-affiliations', ['X-API-Key' => 'vocabulary-key'])
        ->assertOk()->assertStreamed()->assertExactJson(['data' => [], 'total' => 0, 'lastUpdated' => gmdate(DATE_ATOM, 1700000000)]);
});

it('handles empty ROR registries and refreshes the legacy timestamp when only the mtime changes', function (): void {
    $file = 'ror/ror-affiliations.json';
    $url = '/api/v1/vocabularies/data/ror-affiliations';
    $headers = ['X-API-Key' => 'vocabulary-key'];
    Storage::put($file, ' [] ');
    foreach ([1700000000, 1710000000] as $mtime) {
        touch(Storage::path($file), $mtime);
        getJson($url, $headers)->assertOk()->assertExactJson(['lastUpdated' => gmdate(DATE_ATOM, $mtime), 'total' => 0, 'data' => []]);
    }
    Storage::put($file, '{"lastUpdated":"2026-11-01T00:00:00Z","total":0,"data":[]}');
    getJson($url, $headers)->assertOk()->assertStreamedContent(Storage::get($file));
});

it('rejects a same-size corrupt ROR replacement and serves the repaired registry', function (): void {
    $url = '/api/v1/vocabularies/data/ror-affiliations';
    $file = 'ror/ror-affiliations.json';
    $headers = ['X-API-Key' => 'vocabulary-key'];
    $mtime = 1700000000;
    foreach (['[]', '{}', '[]'] as $json) {
        Storage::put($file, $json);
        touch(Storage::path($file), $mtime);
        $response = getJson($url, $headers);
        if ($json === '{}') {
            $response->assertInternalServerError()->assertJsonStructure(['error']);
        } else {
            $response->assertOk()->assertExactJson(['lastUpdated' => gmdate(DATE_ATOM, $mtime), 'total' => 0, 'data' => []]);
        }
    }
});

it('validates the current file independently of legacy caches and recovers as soon as it is repaired', function (): void {
    $file = 'gcmd-platforms.json';
    $url = '/api/v1/vocabularies/data/gcmd-platforms';
    $headers = ['X-API-Key' => 'vocabulary-key'];
    $payload = ['lastUpdated' => '2026-01-15 11:04:04', 'data' => []];
    Storage::put($file, json_encode($payload, JSON_THROW_ON_ERROR));
    getJson($url, $headers)->assertOk()->assertExactJson($payload);
    getJson('/api/v1/vocabularies/gcmd-platforms', $headers)->assertOk();
    Storage::put($file, '{"data":{}}');
    getJson($url, $headers)->assertInternalServerError()->assertJsonStructure(['error']);
    Storage::put($file, '{"data":[]}');
    getJson($url, $headers)->assertOk()->assertExactJson(['data' => []]);
});

it('requires analytical notation on child concepts even when the same tree is valid GCMD', function (): void {
    $node = ['id' => 'https://example.org/concept', 'text' => 'Term', 'scheme' => 'Test',
        'schemeURI' => 'https://example.org', 'language' => 'en', 'description' => '', 'children' => []];
    $payload = ['data' => [array_replace($node, ['notation' => 'T', 'children' => [$node]])]];
    foreach (['gcmd-platforms', 'analytical-methods'] as $slug) {
        Storage::put($slug.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
        getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
            ->assertStatus($slug === 'gcmd-platforms' ? 200 : 500);
    }
});

it('serves the MSL producer tree according to the published schema in both snapshot formats', function (bool $wrapped): void {
    Http::fake(['*msl_vocabularies*' => Http::response([[
        'text' => 'Materials',
        'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/materials'],
        'children' => [
            ['text' => 'Rock', 'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/rock'], 'children' => []],
            ['text' => 'Other materials'],
        ],
    ]])]);
    $service = new MslVocabularyService;
    expect($service->downloadAndTransformVocabulary())->toBeTrue();
    $payload = $service->getVocabulary();
    if ($wrapped) {
        $payload = ['lastUpdated' => '2026-11-09T12:00:00Z', 'data' => $payload];
        Storage::put('msl-vocabulary.json', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    $response = getJson('/api/v1/vocabularies/data/msl', ['X-API-Key' => 'vocabulary-key'])->assertOk()->assertExactJson($payload);
    $snapshot = $response->getData();
    $tree = $wrapped ? $snapshot->data : $snapshot;
    $leaf = $tree[0]->children[0];
    expect($leaf->text)->toBe('Rock')->and($leaf)->not->toHaveProperty('children')
        ->and($tree[0]->children[1]->id)->toBe('');

    $docUrl = url('/api/v1/doc');
    $validator = new Validator;
    $validator->resolver()->registerRaw(getJson($docUrl)->assertOk()->getContent(), $docUrl);
    $schema = $docUrl.'#/components/schemas/MslKeywords';
    $result = $validator->validate($snapshot, $schema);
    expect((new ErrorFormatter)->formatOutput($result->error(), 'basic'))->toBe(['valid' => true]);

    $leaf->children = null;
    expect($validator->validate($snapshot, $schema)->isValid())->toBeFalse();
    $leaf->children = [];
    expect($validator->validate($snapshot, $schema)->isValid())->toBeTrue();
    unset($leaf->text);
    expect($validator->validate($snapshot, $schema)->isValid())->toBeFalse();
})->with(['plain tree' => false, 'legacy wrapper' => true]);

it('documents each vocabulary schema and deprecates precisely the superseded operations', function (): void {
    $spec = getJson('/api/v1/doc')->assertOk()->assertJsonPath('openapi', '3.2.1')->assertJsonPath('info.version', '1.2.0')->json();
    $paths = $spec['paths'];
    $schemas = $spec['components']['schemas'];
    $contract = publicVocabularyContract();
    $slugs = array_keys($contract);
    $security = [['ElmoApiKey' => []], ['ErnieBearerKey' => []]];
    $dataPath = '/api/v1/vocabularies/data/{vocab}';
    $data = $paths[$dataPath]['get'];
    $parameter = $data['parameters'][0];
    expect($parameter['name'])->toBe('vocab')->and($parameter['in'])->toBe('path')->and($parameter['required'])->toBeTrue()
        ->and($parameter['schema']['enum'])->toEqualCanonicalizing($slugs)
        ->and($data['security'])->toBe($security)->and($data['deprecated'] ?? false)->toBeFalse();
    $alternatives = array_column($data['responses']['200']['content']['application/json']['schema']['anyOf'], '$ref', 'title');
    expect(array_keys($alternatives))->toEqualCanonicalizing($slugs);

    $visibility = $schemas['VocabularyVisibility'];
    expect($visibility['type'])->toBe('object')->and($visibility['required'])->toEqualCanonicalizing($slugs)
        ->and(array_keys($visibility['properties']))->toEqualCanonicalizing($slugs);
    $deprecated = [];
    foreach (['elmo', 'elmo-msl'] as $editor) {
        $visibilityPath = "/api/v1/{$editor}/vocabularies/visibility";
        $operation = $paths[$visibilityPath]['get'];
        expect($operation['security'])->toBe($security)->and($operation['deprecated'] ?? false)->toBeFalse()
            ->and($operation['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/VocabularyVisibility');
        foreach (['thesauri', 'pid'] as $category) {
            $deprecated["/api/v1/{$editor}/vocabularies/{$category}-availability"] = $visibilityPath;
        }
        foreach ($contract as $slug => [, , $schema]) {
            expect($visibility['properties'][$slug]['type'])->toBe('boolean')
                ->and($alternatives[$slug])->toBe('#/components/schemas/'.$schema)
                ->and($schemas)->toHaveKey($schema);
            $oldPath = $slug === 'ror-affiliations' ? "/api/v1/ror-affiliations/{$editor}"
                : ($editor === 'elmo' ? '/api/v1/vocabularies/' : '/api/v1/elmo-msl/vocabularies/').$slug;
            $deprecated[$oldPath] = '/api/v1/vocabularies/data/'.$slug;
        }
    }

    expect($deprecated)->toHaveCount(30);
    foreach ($deprecated as $path => $successor) {
        expect($paths[$path]['get']['deprecated'])->toBeTrue()
            ->and($paths[$path]['get']['description'])->toContain('1.2.0', $successor);
    }
    foreach (['/api/v1/vocabularies/pid-availability', '/api/v1/vocabularies/thesauri-availability', '/api/v1/resource-types/elmo-msl'] as $path) {
        expect($paths)->toHaveKey($path)->and($paths[$path]['get']['deprecated'] ?? false)->toBeFalse();
    }
});

class RorReadCountingFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $this->params->bytes += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
