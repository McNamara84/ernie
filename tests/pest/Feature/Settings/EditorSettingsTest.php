<?php

use App\Models\Language;
use App\Models\License;
use App\Models\ResourceType;
use App\Models\Setting;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login when accessing editor settings', function () {
    $this->get(route('settings'))->assertRedirect(route('login'));
});

test('authenticated users can view editor settings page', function () {
    $user = User::factory()->create();
    ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
    License::create(['identifier' => 'MIT', 'name' => 'MIT License']);
    Language::create(['code' => 'en', 'name' => 'English']);
    Setting::create(['key' => 'max_titles', 'value' => (string) Setting::DEFAULT_LIMIT]);
    Setting::create(['key' => 'max_licenses', 'value' => (string) Setting::DEFAULT_LIMIT]);
    $this->actingAs($user);
    withoutVite();
    $response = $this->get(route('settings'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/index')
        ->has('resourceTypes', 1)
        ->has('titleTypes', 1)
        ->has('licenses', 1)
        ->has('languages', 1)
        ->where('resourceTypes.0.active', true)
        ->where('resourceTypes.0.elmo_active', false)
        ->where('titleTypes.0.active', true)
        ->where('titleTypes.0.elmo_active', false)
        ->where('licenses.0.active', true)
        ->where('licenses.0.elmo_active', false)
        ->where('languages.0.active', true)
        ->where('languages.0.elmo_active', false)
        ->where('maxTitles', Setting::DEFAULT_LIMIT)
        ->where('maxLicenses', Setting::DEFAULT_LIMIT)
    );
});

test('authenticated users can update resource and title types and settings', function () {
    $user = User::factory()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
    $license = License::create(['identifier' => 'MIT', 'name' => 'MIT License']);
    $language = Language::create(['code' => 'en', 'name' => 'English']);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '2']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Data Set', 'active' => false, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main', 'slug' => 'main', 'active' => false, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $license->id, 'active' => false, 'elmo_active' => true],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => false, 'elmo_active' => true],
        ],
        'maxTitles' => 10,
        'maxLicenses' => 7,
    ])->assertRedirect();

    $this->assertDatabaseHas('resource_types', [
        'id' => $type->id,
        'name' => 'Data Set',
        'active' => false,
        'elmo_active' => true,
    ]);
    $this->assertDatabaseHas('title_types', [
        'id' => $title->id,
        'name' => 'Main',
        'slug' => 'main',
        'active' => false,
        'elmo_active' => true,
    ]);
    $this->assertDatabaseHas('licenses', [
        'id' => $license->id,
        'active' => false,
        'elmo_active' => true,
    ]);
    $this->assertDatabaseHas('languages', [
        'id' => $language->id,
        'active' => false,
        'elmo_active' => true,
    ]);
    expect(Setting::getValue('max_titles'))->toBe('10');
    expect(Setting::getValue('max_licenses'))->toBe('7');
});

test('updating settings with invalid data returns errors', function () {
    $user = User::factory()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
    $license = License::create(['identifier' => 'MIT', 'name' => 'MIT License']);
    $language = Language::create(['code' => 'en', 'name' => 'English']);
    $this->actingAs($user);

    $response = $this->from(route('settings'))
        ->post(route('settings.update'), [
            'resourceTypes' => [
                ['id' => $type->id, 'name' => 'Data Set', 'active' => true, 'elmo_active' => false],
            ],
            'titleTypes' => [
                ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'main-title', 'active' => true, 'elmo_active' => false],
            ],
            'licenses' => [
                ['id' => $license->id, 'active' => true, 'elmo_active' => false],
            ],
            'languages' => [
                ['id' => $language->id, 'active' => true, 'elmo_active' => false],
            ],
            'maxTitles' => 0,
            'maxLicenses' => 7,
        ]);

    $response->assertSessionHasErrors('maxTitles')
        ->assertRedirect(route('settings'));
});
