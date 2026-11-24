<?php

declare(strict_types=1);

use App\Http\Controllers\VocabularyController;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

use function Pest\Laravel\getJson;

covers(VocabularyController::class);

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

it('rejects an unknown slug and internal setting keys', function (string $slug): void {
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertNotFound()->assertExactJson(['error' => 'Unknown vocabulary.']);
})->with(['unknown', 'science_keywords', 'ror']);

it('reports unreadable files through the shared data endpoint', function (string $slug, string $file): void {
    Storage::shouldReceive('exists')->with($file)->once()->andReturn(true);
    Storage::shouldReceive('get')->with($file)->once()->andReturn(null);
    getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'vocabulary-key'])
        ->assertStatus(500)->assertExactJson(['error' => 'Failed to read vocabulary file.']);
})->with([
    ['gcmd-platforms', 'gcmd-platforms.json'],
    ['ror-affiliations', 'ror/ror-affiliations.json'],
]);

it('reports a storage failure separately from a missing vocabulary', function (): void {
    Storage::shouldReceive('exists')->with('gcmd-platforms.json')->once()
        ->andThrow(UnableToCheckFileExistence::forLocation('gcmd-platforms.json'));
    getJson('/api/v1/vocabularies/data/gcmd-platforms', ['X-API-Key' => 'vocabulary-key'])
        ->assertStatus(500)->assertExactJson(['error' => 'Failed to read vocabulary file.']);
});

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

it('preserves the MSL concept hierarchy in both supported snapshot formats', function (bool $wrapped): void {
    $tree = [['id' => 'material', 'text' => 'Material', 'children' => [['id' => 'rock', 'text' => 'Rock', 'children' => []]]]];
    $payload = $wrapped ? ['lastUpdated' => '2026-11-09T12:00:00Z', 'data' => $tree] : $tree;
    Storage::put('msl-vocabulary.json', json_encode($payload, JSON_THROW_ON_ERROR));
    getJson('/api/v1/vocabularies/data/msl', ['X-API-Key' => 'vocabulary-key'])->assertOk()->assertExactJson($payload);
})->with([false, true]);

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
