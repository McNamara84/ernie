<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('gcmdScienceKeywords', function () {
    test('returns 404 when vocabulary file does not exist', function () {
        Storage::fake();

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-science-keywords');

        $response->assertNotFound();
    });

    test('returns vocabulary data from cached file', function () {
        Storage::fake();
        Storage::put('gcmd-science-keywords.json', json_encode([
            ['id' => '1', 'text' => 'Earth Science'],
        ]));

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-science-keywords');

        $response->assertOk()
            ->assertJsonCount(1);
    });

    test('returns 500 for corrupted JSON file', function () {
        Storage::fake();
        Storage::put('gcmd-science-keywords.json', '{invalid json');

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-science-keywords');

        $response->assertStatus(500);
    });

    test('returns 404 when thesaurus is disabled', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-science-keywords');

        $response->assertNotFound()
            ->assertJson(['error' => 'Thesaurus is disabled']);
    });
});

describe('gcmdPlatforms', function () {
    test('returns 404 when file missing', function () {
        Storage::fake();

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-platforms');

        $response->assertNotFound();
    });
});

describe('gcmdInstruments', function () {
    test('returns 404 when file missing', function () {
        Storage::fake();

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/gcmd-instruments');

        $response->assertNotFound();
    });
});

describe('mslVocabulary', function () {
    test('returns 404 when vocabulary file missing', function () {
        Storage::fake();

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/msl');

        $response->assertNotFound();
    });

    test('returns data from file', function () {
        Storage::fake();
        Storage::put('msl-vocabulary.json', json_encode([['text' => 'Material']]));

        $response = $this->actingAs($this->user)
            ->getJson('/vocabularies/msl');

        $response->assertOk()
            ->assertJsonCount(1);
    });
});

describe('thesauriAvailability', function () {
    test('returns availability status for all thesauri', function () {
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
        ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => false,
            'is_elmo_active' => false,
        ]);

        $response = $this->getJson('/api/v1/vocabularies/thesauri-availability');

        $response->assertOk()
            ->assertJsonPath(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS.'.available', true)
            ->assertJsonPath(ThesaurusSetting::TYPE_PLATFORMS.'.available', false);
    });
});
