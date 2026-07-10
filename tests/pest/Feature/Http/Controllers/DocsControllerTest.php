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

it('passes datacite settings to the frontend', function () {
    config([
        'datacite.test_mode' => false,
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.prefixes' => ['10.5880'],
        'datacite.test.endpoint' => 'https://api.test.example.org',
        'datacite.production.endpoint' => 'https://api.example.org',
    ]);

    $user = User::factory()->curator()->create();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->where('dataCite.currentMode', 'production')
        ->where('dataCite.isTestModeForcedForUser', false)
        ->where('dataCite.testPrefixes', ['10.83279'])
        ->where('dataCite.productionPrefixes', ['10.5880'])
        ->where('dataCite.testEndpoint', 'https://api.test.example.org')
        ->where('dataCite.productionEndpoint', 'https://api.example.org')
    );
});

it('documents beginner users as forced to datacite test mode when global production mode is enabled', function () {
    config([
        'datacite.test_mode' => false,
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.prefixes' => ['10.5880'],
    ]);

    $user = User::factory()->beginner()->create();

    $response = $this->actingAs($user)->get('/docs');

    $response->assertInertia(fn ($page) => $page
        ->where('dataCite.currentMode', 'test')
        ->where('dataCite.isTestModeForcedForUser', true)
        ->where('dataCite.testPrefixes', ['10.83279'])
        ->where('dataCite.productionPrefixes', ['10.5880'])
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
