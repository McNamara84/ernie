<?php

declare(strict_types=1);

use App\Http\Controllers\DocsController;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);
covers(DocsController::class);

it('requires authentication', function () {
    $response = $this->get('/docs');

    $response->assertRedirect('/login');
});

it('renders the docs page for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('docs'));
});

it('passes the user role to the frontend', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page->has('userRole'));
});

it('passes editor settings to the frontend', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->has('editorSettings.thesauri')
        ->has('editorSettings.features')
        ->has('editorSettings.limits')
    );
});

it('includes thesauri settings', function () {
    ThesaurusSetting::create([
        'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'display_name' => 'Science Keywords',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    $user = User::factory()->create();
    Cache::flush();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->where('editorSettings.thesauri.scienceKeywords', true)
    );
});

it('includes feature availability flags', function () {
    Right::factory()->create(['is_active' => true]);
    ResourceType::factory()->create(['is_active' => true]);
    TitleType::factory()->create(['is_active' => true]);
    Language::factory()->create(['active' => true]);

    $user = User::factory()->create();
    Cache::flush();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->where('editorSettings.features.hasActiveLicenses', true)
        ->where('editorSettings.features.hasActiveResourceTypes', true)
        ->where('editorSettings.features.hasActiveTitleTypes', true)
        ->where('editorSettings.features.hasActiveLanguages', true)
    );
});

it('includes limits from settings', function () {
    $user = User::factory()->create();
    Cache::flush();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->has('editorSettings.limits.maxTitles')
        ->has('editorSettings.limits.maxLicenses')
    );
});

it('uses default limits when no settings exist', function () {
    $user = User::factory()->create();
    Cache::flush();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->where('editorSettings.limits.maxTitles', Setting::DEFAULT_LIMIT)
        ->where('editorSettings.limits.maxLicenses', Setting::DEFAULT_LIMIT)
    );
});
