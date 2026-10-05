<?php

declare(strict_types=1);

use App\Enums\EditorContext;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use App\Models\User;
use App\Services\CgiSimpleLithologyVocabularyService;
use App\Services\MslLaboratoryVocabularyService;
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

it('checks each editor before reading shared cached vocabulary data', function (string $type, string $slug, string $file, bool $pid): void {
    $model = $pid ? PidSetting::class : ThesaurusSetting::class;
    $setting = $model::firstOrCreate(['type' => $type], ['display_name' => $type]);
    $payload = ['data' => [['id' => 'test', 'text' => 'Test']]];
    Storage::put($file, json_encode($payload));

    for ($flags = 0; $flags < 8; $flags++) {
        $setting->update([
            'is_active' => (bool) ($flags & 1),
            'is_elmo_active' => (bool) ($flags & 2),
            'is_elmo_msl_active' => (bool) ($flags & 4),
        ]);
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
    Storage::put('ror/ror-affiliations.json', json_encode(['total' => 1, 'data' => [['id' => 'ror-test']]]));
    $this->getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'editor-test-key'])->assertOk();
    $this->getJson('/api/v1/ror-affiliations/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    $this->getJson('/api/v1/vocabularies/pid-availability', ['X-API-Key' => 'editor-test-key'])->assertJsonPath('ror.available', false);
    $setting->update(['is_elmo_active' => false, 'is_elmo_msl_active' => true]);
    $this->getJson('/api/v1/ror-affiliations/elmo-msl', ['Authorization' => 'Bearer editor-test-key'])->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
});

it('preserves missing and corrupt file errors for enabled MSL vocabularies', function (): void {
    $this->getJson('/api/v1/elmo-msl/vocabularies/msl', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    Storage::put('msl-vocabulary.json', '{broken');
    $this->getJson('/api/v1/elmo-msl/vocabularies/msl', ['X-API-Key' => 'editor-test-key'])->assertStatus(500);
});

it('isolates validated vocabulary services and their availability for every editor', function (string $type, string $slug, string $service): void {
    $payload = ['total' => 1, 'data' => [['id' => 'test', 'text' => 'Test']]];
    if ($type === ThesaurusSetting::TYPE_MSL_LABORATORIES) {
        $this->mock($service, function ($mock) use ($payload): void {
            $mock->shouldReceive('getPublicPayload')->andReturn($payload);
            $mock->shouldReceive('getLocalPayload')->andReturn($payload);
        });
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
    [ThesaurusSetting::TYPE_MSL_LABORATORIES, 'msl-laboratories', MslLaboratoryVocabularyService::class],
    [ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY, 'cgi-simple-lithology', CgiSimpleLithologyVocabularyService::class],
]);
