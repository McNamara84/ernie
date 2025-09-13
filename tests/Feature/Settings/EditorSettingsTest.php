<?php

use App\Models\User;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\Setting;
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
    Setting::create(['key' => 'max_titles', 'value' => (string) Setting::DEFAULT_LIMIT]);
    Setting::create(['key' => 'max_licenses', 'value' => (string) Setting::DEFAULT_LIMIT]);
    $this->actingAs($user);
    withoutVite();
    $response = $this->get(route('settings'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/index')
        ->has('resourceTypes', 1)
        ->has('titleTypes', 1)
        ->where('resourceTypes.0.active', true)
        ->where('resourceTypes.0.elmo_active', false)
        ->where('titleTypes.0.active', true)
        ->where('titleTypes.0.elmo_active', false)
        ->where('maxTitles', Setting::DEFAULT_LIMIT)
        ->where('maxLicenses', Setting::DEFAULT_LIMIT)
    );
});

test('authenticated users can update resource and title types and settings', function () {
    $user = User::factory()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
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
    expect(Setting::getValue('max_titles'))->toBe('10');
    expect(Setting::getValue('max_licenses'))->toBe('7');
});

test('updating settings with invalid data returns errors', function () {
    $user = User::factory()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
    $this->actingAs($user);

    $response = $this->from(route('settings'))
        ->post(route('settings.update'), [
            'resourceTypes' => [
                ['id' => $type->id, 'name' => 'Data Set', 'active' => true, 'elmo_active' => false],
            ],
            'titleTypes' => [
                ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'main-title', 'active' => true, 'elmo_active' => false],
            ],
            'maxTitles' => 0,
            'maxLicenses' => 7,
        ]);

    $response->assertSessionHasErrors('maxTitles')
        ->assertRedirect(route('settings'));
});
