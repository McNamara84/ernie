<?php

use App\Enums\CacheKey;
use App\Enums\UserRole;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear the docs editor settings cache before each test
    Cache::forget(CacheKey::DOCS_EDITOR_SETTINGS->key());
});

test('guests are redirected to login when visiting docs', function () {
    $this->get(route('docs'))->assertRedirect(route('login'));
});

test('authenticated users can view the docs page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();
    $response = $this->get(route('docs'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->has('userRole'));
});

test('docs page includes editor settings for dynamic content', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->has('userRole')
        ->has('editorSettings')
        ->has('editorSettings.thesauri')
        ->has('editorSettings.thesauri.scienceKeywords')
        ->has('editorSettings.thesauri.platforms')
        ->has('editorSettings.thesauri.instruments')
        ->has('editorSettings.features')
        ->has('editorSettings.features.hasActiveGcmd')
        ->has('editorSettings.features.hasActiveMsl')
        ->has('editorSettings.features.hasActiveLicenses')
        ->has('editorSettings.features.hasActiveResourceTypes')
        ->has('editorSettings.features.hasActiveTitleTypes')
        ->has('editorSettings.features.hasActiveLanguages')
        ->has('editorSettings.limits')
        ->has('editorSettings.limits.maxTitles')
        ->has('editorSettings.limits.maxLicenses'));
});

test('docs page reflects active thesaurus settings', function () {
    // Clear any existing thesaurus settings to ensure clean state
    ThesaurusSetting::query()->delete();

    // Create thesaurus settings with specific active states
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'display_name' => 'Science Keywords',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_PLATFORMS,
        'display_name' => 'Platforms',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
        'display_name' => 'Instruments',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.thesauri.scienceKeywords', true)
        ->where('editorSettings.thesauri.platforms', false)
        ->where('editorSettings.thesauri.instruments', true)
        ->where('editorSettings.features.hasActiveGcmd', true));
});

test('docs page reflects when no thesauri are active', function () {
    // Create thesaurus settings with all inactive
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'display_name' => 'Science Keywords',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_PLATFORMS,
        'display_name' => 'Platforms',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
        'display_name' => 'Instruments',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.features.hasActiveGcmd', false));
});

test('docs page reflects active licenses', function () {
    // Clear existing licenses to ensure clean state
    Right::query()->delete();

    // Create some licenses with mixed active states
    Right::factory()->create(['is_active' => true]);
    Right::factory()->create(['is_active' => false]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.features.hasActiveLicenses', true));
});

test('docs page reflects when no licenses are active', function () {
    // Create only inactive licenses
    Right::factory()->create(['is_active' => false]);
    Right::factory()->create(['is_active' => false]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.features.hasActiveLicenses', false));
});

test('docs page reflects active resource types', function () {
    // Clear existing resource types to ensure clean state
    ResourceType::query()->delete();

    ResourceType::factory()->create(['is_active' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.features.hasActiveResourceTypes', true));
});

test('docs page reflects active languages', function () {
    // Clear existing languages to ensure clean state
    Language::query()->delete();

    Language::factory()->create(['active' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('editorSettings.features.hasActiveLanguages', true));
});

test('docs page passes correct user role for beginners', function () {
    $user = User::factory()->create(['role' => UserRole::BEGINNER]);
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('userRole', 'beginner'));
});

test('docs page passes correct user role for admins', function () {
    $user = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('userRole', 'admin'));
});

test('docs page passes correct user role for group leaders', function () {
    $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('userRole', 'group_leader'));
});

test('docs page passes correct user role for curators', function () {
    $user = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->actingAs($user);
    withoutVite();

    $response = $this->get(route('docs'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('docs')
        ->where('userRole', 'curator'));
});
