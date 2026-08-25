<?php

declare(strict_types=1);

use App\Http\Controllers\VocabularyController;
use App\Models\ThesaurusSetting;
use App\Models\User;
use App\Support\CgiSimpleLithologyVocabularyParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
covers(VocabularyController::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);
    config(['simple_lithology.min_concepts' => 1]);
    Storage::fake('local');
    Cache::flush();
});

function storeSimpleLithologyApiFixture(): void
{
    $payload = app(CgiSimpleLithologyVocabularyParser::class)->buildPayload([[
        'concept' => [
            'type' => 'uri',
            'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
        ],
        'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => 'Basalt'],
    ]], null, 1, 10, 10);

    Storage::disk('local')->put(
        'cgi-simple-lithology.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

it('serves the ERNIE vocabulary only when ERNIE is enabled and a valid local file exists', function (): void {
    storeSimpleLithologyApiFixture();
    ThesaurusSetting::where('type', ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY)->update([
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/vocabularies/cgi-simple-lithology')
        ->assertOk()
        ->assertJsonPath('data.0.text', 'Basalt');

    $this->getJson('/api/v1/vocabularies/cgi-simple-lithology', ['X-API-Key' => 'test-api-key'])
        ->assertNotFound();
});

it('serves the ELMO vocabulary independently through the API-key protected endpoint', function (): void {
    storeSimpleLithologyApiFixture();
    ThesaurusSetting::where('type', ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY)->update([
        'is_active' => false,
        'is_elmo_active' => true,
    ]);

    $this->getJson('/api/v1/vocabularies/cgi-simple-lithology', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonPath('total', 1);
    $this->getJson('/api/v1/vocabularies/cgi-simple-lithology')->assertUnauthorized();
});

it('reports the vocabulary unavailable while the local file is missing or invalid', function (): void {
    ThesaurusSetting::where('type', ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY)->update([
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    $this->getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertOk()
        ->assertJsonPath('simple_lithology.available', false);

    Storage::disk('local')->put('cgi-simple-lithology.json', '{}');
    $this->getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertJsonPath('simple_lithology.available', false);

    storeSimpleLithologyApiFixture();
    $this->getJson('/api/v1/vocabularies/thesauri-availability')
        ->assertJsonPath('simple_lithology.available', true);
});

it('migrates CGI Simple Lithology as disabled for ERNIE and ELMO', function (): void {
    $setting = ThesaurusSetting::where('type', ThesaurusSetting::TYPE_SIMPLE_LITHOLOGY)->firstOrFail();

    expect($setting->is_active)->toBeFalse()
        ->and($setting->is_elmo_active)->toBeFalse()
        ->and($setting->getArtisanCommand())->toBe('get-cgi-simple-lithology')
        ->and($setting->getFilePath())->toBe('cgi-simple-lithology.json');
});
