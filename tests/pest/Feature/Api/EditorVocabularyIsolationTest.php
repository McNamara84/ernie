<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\EditorContext;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use App\Models\User;
use App\Support\CgiSimpleLithologyVocabularyParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'editor-test-key']);
    Storage::fake();
    Cache::flush();
});

dataset('editor vocabulary files', [
    ['science_keywords', 'gcmd-science-keywords', 'gcmd-science-keywords.json', false],
    ['platforms', 'gcmd-platforms', 'gcmd-platforms.json', false],
    ['instruments', 'gcmd-instruments', 'gcmd-instruments.json', false],
    ['msl_keywords', 'msl', 'msl-vocabulary.json', false],
    ['chronostratigraphy', 'chronostrat-timescale', 'chronostrat-timescale.json', false],
    ['gemet', 'gemet', 'gemet-thesaurus.json', false],
    ['analytical_methods', 'analytical-methods', 'analytical-methods.json', false],
    ['euroscivoc', 'euroscivoc', 'euroscivoc.json', false],
    ['pid4inst', 'pid4inst-instruments', 'pid4inst-instruments.json', true],
    ['raid', 'raid-projects', 'raid/raid-projects.json', true],
]);

it('never exposes internal B2INST provenance to any editor, including cached snapshots', function (bool $cached): void {
    $setting = PidSetting::firstOrCreate(['type' => 'pid4inst'], ['display_name' => 'PIDINST']);
    $setting->update(['is_active' => true, 'is_elmo_active' => true, 'is_elmo_msl_active' => true]);
    $public = ['lastUpdated' => '2026-10-29T10:00:00Z', 'total' => 1, 'data' => [[
        'id' => 'abc', 'pid' => '21.11157/abc', 'pidType' => 'Handle', 'name' => 'Instrument',
    ]]];
    $internal = $public + ['_sync' => ['records' => ['abc' => [
        'metadataIdentifier' => ['identifierType' => 'Handle', 'identifierValue' => '21.11157/abc'],
        'providerPid' => 'https://hdl.handle.net/21.11157/uuid',
    ]], 'refreshPending' => true]];
    $internal['data'][0]['lookupIdentifiers'] = [['value' => '21.11157/abc', 'type' => 'Handle', 'source' => 'metadata']];
    Storage::put('pid4inst-instruments.json', json_encode($internal));
    if ($cached) {
        Cache::put(CacheKey::PID4INST_INSTRUMENTS->key(), $internal);
    }
    $this->actingAs(User::factory()->create());
    foreach (['/vocabularies/', '/api/v1/vocabularies/', '/api/v1/elmo-msl/vocabularies/', '/api/v1/vocabularies/data/'] as $prefix) {
        $this->getJson($prefix.'pid4inst-instruments', ['X-API-Key' => 'editor-test-key'])->assertExactJson($public);
    }
})->with([false, true]);

it('checks each editor before reading shared cached vocabulary data', function (string $type, string $slug, string $file, bool $pid): void {
    $model = $pid ? PidSetting::class : ThesaurusSetting::class;
    $setting = $model::firstOrCreate(['type' => $type], ['display_name' => $type]);
    $concept = [
        'id' => 'https://example.org/concepts/child', 'text' => 'Nested term', 'language' => 'en',
        'scheme' => 'Test', 'schemeURI' => 'https://example.org/concepts', 'description' => '', 'children' => [],
    ];
    if ($type === 'analytical_methods') {
        $concept['notation'] = 'T';
    }
    $record = match ($type) {
        'pid4inst' => ['id' => 'test', 'pid' => '21.test/a', 'pidType' => 'Handle', 'name' => 'Test'],
        'raid' => [
            'id' => 'test', 'doi' => '10.1234/test', 'raidId' => null, 'title' => 'Test', 'description' => '',
            'url' => null, 'dates' => [], 'contributors' => [], 'organisations' => [], 'relatedIdentifiers' => [],
            'subjects' => [], 'searchTerms' => ['Test'],
        ],
        default => array_replace($concept, ['id' => 'https://example.org/concepts/root', 'text' => 'Test', 'children' => [$concept]]),
    };
    $payload = ['lastUpdated' => '2026-11-01T00:00:00Z', 'data' => [$record]];
    if ($pid) {
        $payload['total'] = 1;
    }
    Storage::put($file, json_encode($payload));

    for ($flags = 0; $flags < 8; $flags++) {
        $setting->update([
            'is_active' => (bool) ($flags & 1),
            'is_elmo_active' => (bool) ($flags & 2),
            'is_elmo_msl_active' => (bool) ($flags & 4),
        ]);
        $this->getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'editor-test-key'])
            ->assertOk()->assertExactJson($payload);
        foreach (EditorContext::cases() as $index => $editor) {
            $url = match ($editor) {
                EditorContext::ERNIE => '/vocabularies/'.$slug,
                EditorContext::ELMO => '/api/v1/vocabularies/'.$slug,
                EditorContext::ELMO_MSL => '/api/v1/elmo-msl/vocabularies/'.$slug,
            };
            if ($editor === EditorContext::ERNIE) {
                $this->actingAs(User::factory()->create());
            }
            $response = $this->getJson($url, ['X-API-Key' => 'editor-test-key']);
            $enabled = (bool) ($flags & (1 << $index));
            $response->assertStatus($enabled ? 200 : 404);
            if ($enabled) {
                $response->assertExactJson($payload);
            }
            $availability = '/api/v1/'.($editor === EditorContext::ERNIE ? '' : $editor->value.'/').'vocabularies/'.($pid ? 'pid' : 'thesauri').'-availability';
            $this->getJson($availability, ['X-API-Key' => 'editor-test-key'])->assertOk()->assertJsonPath($type.'.available', $enabled);
        }
    }
})->with('editor vocabulary files');

it('uses MSL-specific ROR activation and never infers an editor from a header', function (): void {
    $setting = PidSetting::firstOrCreate(['type' => 'ror'], ['display_name' => 'ROR']);
    $setting->update(['is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => false]);
    $payload = ['lastUpdated' => '2026-11-09T12:00:00Z', 'total' => 1, 'data' => [[
        'prefLabel' => 'GFZ', 'rorId' => 'https://ror.org/04z8jg394', 'otherLabel' => ['GFZ Potsdam'],
    ]]];
    Storage::put('ror/ror-affiliations.json', json_encode($payload));
    $this->getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'editor-test-key'])->assertOk();
    $this->getJson('/api/v1/ror-affiliations/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    $this->getJson('/api/v1/vocabularies/pid-availability', ['X-API-Key' => 'editor-test-key'])->assertJsonPath('ror.available', false);
    $setting->update(['is_elmo_active' => false, 'is_elmo_msl_active' => true]);
    $this->getJson('/api/v1/ror-affiliations/elmo-msl', ['Authorization' => 'Bearer editor-test-key'])->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    $setting->update(['is_elmo_msl_active' => false]);
    $this->getJson('/api/v1/vocabularies/data/ror-affiliations', ['X-API-Key' => 'editor-test-key'])
        ->assertOk()->assertExactJson($payload);
});

it('preserves missing and corrupt file errors for enabled MSL vocabularies', function (): void {
    $this->getJson('/api/v1/elmo-msl/vocabularies/msl', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    Storage::put('msl-vocabulary.json', '{broken');
    $this->getJson('/api/v1/elmo-msl/vocabularies/msl', ['X-API-Key' => 'editor-test-key'])->assertStatus(500);
});

it('isolates validated vocabulary services and their availability for every editor', function (string $type, string $slug): void {
    if ($type === ThesaurusSetting::TYPE_MSL_LABORATORIES) {
        $payload = ['version' => '1.2', 'lastUpdated' => '2026-11-01T00:00:00Z', 'total' => 0, 'data' => []];
        $source = ['repository' => 'msl', 'ref' => 'main', 'path' => 'labs.json', 'sha' => str_repeat('a', 40)];
        Storage::put('msl-laboratories.json', json_encode($payload + ['source' => $source], JSON_THROW_ON_ERROR));
    } else {
        config(['simple_lithology.min_concepts' => 1]);
        $payload = app(CgiSimpleLithologyVocabularyParser::class)->buildPayload([[
            'concept' => ['type' => 'uri', 'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt'],
            'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => 'Basalt'],
        ]], null, 1, 10, 10, 100);
        Storage::put('cgi-simple-lithology.json', json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $this->actingAs(User::factory()->create());
    $setting = ThesaurusSetting::where('type', $type)->firstOrFail();
    for ($flags = 0; $flags < 8; $flags++) {
        $setting->update(['is_active' => (bool) ($flags & 1), 'is_elmo_active' => (bool) ($flags & 2), 'is_elmo_msl_active' => (bool) ($flags & 4)]);
        $this->getJson('/api/v1/vocabularies/data/'.$slug, ['X-API-Key' => 'editor-test-key'])
            ->assertOk()->assertExactJson($payload);
        foreach (EditorContext::cases() as $index => $editor) {
            $prefix = match ($editor) {
                EditorContext::ERNIE => '/vocabularies/',
                EditorContext::ELMO => '/api/v1/vocabularies/',
                EditorContext::ELMO_MSL => '/api/v1/elmo-msl/vocabularies/',
            };
            $enabled = (bool) ($flags & (1 << $index));
            $response = $this->getJson($prefix.$slug, ['X-API-Key' => 'editor-test-key'])->assertStatus($enabled ? 200 : 404);
            if ($enabled) {
                $response->assertExactJson($payload);
            }
            $availability = '/api/v1/'.($editor === EditorContext::ERNIE ? '' : $editor->value.'/').'vocabularies/thesauri-availability';
            $this->getJson($availability, ['X-API-Key' => 'editor-test-key'])->assertJsonPath($type.'.available', $enabled);
        }
    }
})->with([
    [ThesaurusSetting::TYPE_MSL_LABORATORIES, 'msl-laboratories'],
    [ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY, 'cgi-simple-lithology'],
]);
