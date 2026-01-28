<?php

use App\Enums\UserRole;
use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed thesaurus settings
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'display_name' => 'Science Keywords',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_PLATFORMS,
        'display_name' => 'Platforms',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
        'display_name' => 'Instruments',
        'is_active' => false,
        'is_elmo_active' => true,
    ]);
});

describe('ThesaurusSettingsController', function () {
    test('guests cannot access thesaurus settings', function () {
        $this->get('/thesauri')
            ->assertRedirect('/login');
    });

    test('non-admin users cannot access thesaurus settings', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $this->actingAs($user)
            ->get('/thesauri')
            ->assertForbidden();
    });

    test('admins can list thesauri', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/thesauri')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment([
                'type' => 'science_keywords',
                'displayName' => 'Science Keywords',
                'isActive' => true,
                'isElmoActive' => true,
            ]);
    });

    test('only admins can trigger thesaurus updates', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);

        $this->actingAs($curator)
            ->postJson('/thesauri/science_keywords/update')
            ->assertForbidden();
    });

    test('admins can trigger thesaurus updates', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/thesauri/science_keywords/update')
            ->assertOk()
            ->assertJsonStructure(['jobId', 'message']);
    });

    test('admins can poll update status', function () {
        $admin = User::factory()->admin()->create();
        $jobId = Str::uuid()->toString();

        Cache::put("thesaurus_update:{$jobId}", [
            'status' => 'running',
            'thesaurusType' => 'science_keywords',
        ], now()->addHour());

        $this->actingAs($admin)
            ->getJson("/thesauri/update-status/{$jobId}")
            ->assertOk()
            ->assertJsonFragment(['status' => 'running']);
    });

    test('polling returns 404 for unknown job', function () {
        $admin = User::factory()->admin()->create();
        $unknownJobId = Str::uuid()->toString();

        $this->actingAs($admin)
            ->getJson("/thesauri/update-status/{$unknownJobId}")
            ->assertNotFound();
    });
});

describe('VocabularyController with Thesaurus Settings', function () {
    test('returns 404 when thesaurus is disabled for ERNIE', function () {
        Storage::fake();
        Storage::put('gcmd-instruments.json', json_encode(['data' => []]));

        $user = User::factory()->create();

        // Instruments is disabled for ERNIE (is_active = false)
        $this->actingAs($user)
            ->getJson('/vocabularies/gcmd-instruments')
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'Thesaurus is disabled']);
    });

    test('returns vocabulary when thesaurus is enabled for ERNIE', function () {
        Storage::fake();
        Storage::put('gcmd-science-keywords.json', json_encode([
            'data' => [['id' => '1', 'text' => 'Earth Science']],
        ]));

        $user = User::factory()->create();

        // Science Keywords is enabled for ERNIE (is_active = true)
        $this->actingAs($user)
            ->getJson('/vocabularies/gcmd-science-keywords')
            ->assertOk()
            ->assertJsonFragment(['text' => 'Earth Science']);
    });

    test('thesauri-availability endpoint returns correct status', function () {
        $this->getJson('/api/v1/vocabularies/thesauri-availability')
            ->assertOk()
            ->assertJson([
                'science_keywords' => ['available' => true, 'displayName' => 'Science Keywords'],
                'platforms' => ['available' => true, 'displayName' => 'Platforms'],
                'instruments' => ['available' => false, 'displayName' => 'Instruments'],
            ]);
    });

    test('ELMO requests check is_elmo_active instead of is_active', function () {
        Storage::fake();
        Storage::put('gcmd-instruments.json', json_encode([
            'data' => [['id' => '1', 'text' => 'Spectrometer']],
        ]));

        // Configure ELMO API key for test
        Config::set('services.ernie.api_key', 'test-api-key');

        // Instruments: is_active = false, is_elmo_active = true
        // ELMO request should succeed
        $this->withHeaders(['X-API-Key' => 'test-api-key'])
            ->getJson('/api/v1/vocabularies/gcmd-instruments')
            ->assertOk();
    });

    test('ELMO requests fail when is_elmo_active is false', function () {
        Storage::fake();
        Storage::put('gcmd-platforms.json', json_encode(['data' => []]));

        // Configure ELMO API key for test
        Config::set('services.ernie.api_key', 'test-api-key');

        // Platforms: is_active = true, is_elmo_active = false
        // ELMO request should fail
        $this->withHeaders(['X-API-Key' => 'test-api-key'])
            ->getJson('/api/v1/vocabularies/gcmd-platforms')
            ->assertNotFound();
    });
});

describe('EditorSettings with Thesauri', function () {
    test('thesauri are included in editor settings page data', function () {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $response = $this->get(route('settings'));

        $response->assertInertia(fn ($assert) => $assert
            ->has('thesauri', 3)
            ->where('thesauri.0.type', 'science_keywords')
            ->where('thesauri.0.isActive', true)
            ->where('thesauri.0.isElmoActive', true)
        );
    });

    test('thesauri settings can be updated via editor settings', function () {
        // Create required test data for all mandatory fields
        $resourceType = \App\Models\ResourceType::create([
            'name' => 'Dataset',
            'slug' => 'Dataset',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
        $titleType = \App\Models\TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'is_active' => true, 'is_elmo_active' => true]
        );
        $license = \App\Models\Right::create([
            'identifier' => 'CC-BY-4.0',
            'name' => 'CC BY 4.0',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
        $language = \App\Models\Language::create([
            'code' => 'en',
            'name' => 'English',
            'active' => true,
            'elmo_active' => true,
        ]);
        $dateType = \App\Models\DateType::create([
            'name' => 'Created',
            'slug' => 'Created',
            'is_active' => true,
        ]);

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $this->post(route('settings.update'), [
            'resourceTypes' => [
                ['id' => $resourceType->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
            ],
            'titleTypes' => [
                ['id' => $titleType->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
            ],
            'licenses' => [
                ['id' => $license->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
            ],
            'languages' => [
                ['id' => $language->id, 'active' => true, 'elmo_active' => true],
            ],
            'dateTypes' => [
                ['id' => $dateType->id, 'active' => true],
            ],
            'maxTitles' => 10,
            'maxLicenses' => 10,
            'thesauri' => [
                ['type' => 'science_keywords', 'isActive' => false, 'isElmoActive' => true],
                ['type' => 'platforms', 'isActive' => true, 'isElmoActive' => true],
                ['type' => 'instruments', 'isActive' => true, 'isElmoActive' => false],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('thesaurus_settings', [
            'type' => 'science_keywords',
            'is_active' => false,
            'is_elmo_active' => true,
        ]);
        $this->assertDatabaseHas('thesaurus_settings', [
            'type' => 'platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
        $this->assertDatabaseHas('thesaurus_settings', [
            'type' => 'instruments',
            'is_active' => true,
            'is_elmo_active' => false,
        ]);
    });
});
