<?php

use App\Models\User;
use App\Models\ResourceType;
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
    Setting::create(['key' => 'max_titles', 'value' => '99']);
    Setting::create(['key' => 'max_licenses', 'value' => '99']);
    $this->actingAs($user);
    withoutVite();
    $response = $this->get(route('settings'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/index')
        ->has('resourceTypes', 1)
        ->where('maxTitles', 99)
        ->where('maxLicenses', 99)
    );
});

test('authenticated users can update resource types and settings', function () {
    $user = User::factory()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '2']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Data Set'],
        ],
        'maxTitles' => 10,
        'maxLicenses' => 7,
    ])->assertRedirect();

    $this->assertDatabaseHas('resource_types', ['id' => $type->id, 'name' => 'Data Set']);
    expect(Setting::getValue('max_titles'))->toBe('10');
    expect(Setting::getValue('max_licenses'))->toBe('7');
});
