<?php

declare(strict_types=1);

use App\Http\Controllers\VocabularyController;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
covers(VocabularyController::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake();
});

describe('gcmd science keywords', function () {
    it('returns vocabulary when thesaurus is active', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('gcmd-science-keywords.json', json_encode([['term' => 'Atmosphere']]));

        $response = $this->getJson('/api/v1/vocabularies/gcmd-science-keywords', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJson([['term' => 'Atmosphere']]);
    });

    it('returns 404 when thesaurus is disabled', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/gcmd-science-keywords', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });

    it('returns 404 when vocabulary file does not exist', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/gcmd-science-keywords', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });
});

describe('gcmd platforms', function () {
    it('returns vocabulary when thesaurus is active', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('gcmd-platforms.json', json_encode([['term' => 'Satellite']]));

        $response = $this->getJson('/api/v1/vocabularies/gcmd-platforms', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });

    it('returns 404 when thesaurus is disabled', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/gcmd-platforms', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });
});

describe('gcmd instruments', function () {
    it('returns vocabulary when thesaurus is active', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
            'display_name' => 'Instruments',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('gcmd-instruments.json', json_encode([['term' => 'Seismometer']]));

        $response = $this->getJson('/api/v1/vocabularies/gcmd-instruments', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });
});

describe('msl vocabulary', function () {
    it('returns vocabulary when file exists', function () {
        Storage::put('msl-vocabulary.json', json_encode([['term' => 'Rock Mechanics']]));

        $response = $this->getJson('/api/v1/vocabularies/msl', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJson([['term' => 'Rock Mechanics']]);
    });

    it('returns 404 when vocabulary file is missing', function () {
        $response = $this->getJson('/api/v1/vocabularies/msl', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });
});

describe('pid4inst instruments', function () {
    it('returns vocabulary when PID setting is active', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_PID4INST,
            'display_name' => 'PID4INST',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('pid4inst-instruments.json', json_encode([['name' => 'Magnetometer']]));

        $response = $this->getJson('/api/v1/vocabularies/pid4inst-instruments', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });

    it('returns 404 when PID setting is disabled', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_PID4INST,
            'display_name' => 'PID4INST',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/pid4inst-instruments', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });
});

describe('chronostrat timescale', function () {
    it('returns vocabulary when thesaurus is active', function () {
        ThesaurusSetting::firstOrCreate(
            ['type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
            [
                'display_name' => 'Chronostratigraphy',
                'is_active' => true,
                'is_elmo_active' => true,
            ],
        );

        Storage::put('chronostrat-timescale.json', json_encode([['era' => 'Cenozoic']]));

        $response = $this->getJson('/api/v1/vocabularies/chronostrat-timescale', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });
});

describe('gemet thesaurus', function () {
    it('returns vocabulary when thesaurus is active', function () {
        ThesaurusSetting::firstOrCreate(
            ['type' => ThesaurusSetting::TYPE_GEMET],
            [
                'display_name' => 'GEMET',
                'is_active' => true,
                'is_elmo_active' => true,
            ],
        );

        Storage::put('gemet-thesaurus.json', json_encode([['concept' => 'geology']]));

        $response = $this->getJson('/api/v1/vocabularies/gemet', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });
});

describe('ror affiliations', function () {
    it('returns 404 when ROR is disabled', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_ROR,
            'display_name' => 'ROR',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/ror-affiliations/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });

    it('returns 404 when vocabulary file is missing', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_ROR,
            'display_name' => 'ROR',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $response = $this->getJson('/api/v1/ror-affiliations/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertNotFound();
    });

    it('returns ror affiliations when file exists and ROR is active', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_ROR,
            'display_name' => 'ROR',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('ror/ror-affiliations.json', json_encode(['total' => 1, 'items' => [['name' => 'GFZ']]]));

        $response = $this->getJson('/api/v1/ror-affiliations/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk();
    });
});

describe('thesauri availability', function () {
    it('returns thesauri availability status for ernie', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/thesauri-availability');

        $response->assertOk()
            ->assertJsonPath('science_keywords.available', true)
            ->assertJsonPath('science_keywords.displayName', 'Science Keywords');
    });

    it('returns elmo-specific availability when called through elmo route', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/elmo/vocabularies/thesauri-availability', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonPath('science_keywords.available', false);
    });
});

describe('pid availability', function () {
    it('returns PID availability status', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_PID4INST,
            'display_name' => 'PID4INST',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/pid-availability');

        $response->assertOk()
            ->assertJsonPath('pid4inst.available', true)
            ->assertJsonPath('pid4inst.displayName', 'PID4INST');
    });

    it('returns elmo-specific PID availability when called through elmo route', function () {
        PidSetting::create([
            'type' => PidSetting::TYPE_PID4INST,
            'display_name' => 'PID4INST',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/elmo/vocabularies/pid-availability', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonPath('pid4inst.available', false);
    });
});

describe('corrupted vocabulary', function () {
    it('returns 500 when vocabulary file contains invalid JSON', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('gcmd-science-keywords.json', 'not-valid-json{{{');

        $response = $this->getJson('/api/v1/vocabularies/gcmd-science-keywords', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertStatus(500);
    });

    it('returns 500 when vocabulary file contains null', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Storage::put('gcmd-science-keywords.json', 'null');

        $response = $this->getJson('/api/v1/vocabularies/gcmd-science-keywords', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertStatus(500);
    });
});
