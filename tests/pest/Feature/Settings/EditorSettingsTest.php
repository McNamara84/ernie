<?php

use App\Models\DateType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
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

test('admin users can view editor settings page', function () {
    // Issue #379: Only Admin and Group Leader can access Editor Settings
    $user = User::factory()->admin()->create();
    ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]);
    TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]);
    Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true]);
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
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
        // 1 dateType created in this test
        ->has('dateTypes', 1)
        ->where('maxTitles', Setting::DEFAULT_LIMIT)
        ->where('maxLicenses', Setting::DEFAULT_LIMIT)
    );
});

test('admin users can update resource and title types and settings', function () {
    // Issue #379: Only Admin and Group Leader can access Editor Settings
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
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
            ['id' => $right->id, 'active' => false, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => false, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => false],
        ],
        'maxTitles' => 10,
        'maxLicenses' => 7,
    ])->assertRedirect();

    $this->assertDatabaseHas('resource_types', [
        'id' => $type->id,
        'name' => 'Data Set',
        'is_active' => false,
        'is_elmo_active' => true,
    ]);
    $this->assertDatabaseHas('title_types', [
        'id' => $title->id,
        'name' => 'Main',
        'slug' => 'main',
        'is_active' => false,
        'is_elmo_active' => true,
    ]);
    $this->assertDatabaseHas('rights', [
        'id' => $right->id,
        'is_active' => false,
        'is_elmo_active' => true,
    ]);
    $this->assertDatabaseHas('languages', [
        'id' => $language->id,
        'active' => false,
        'elmo_active' => true,
    ]);
    $this->assertDatabaseHas('date_types', [
        'id' => $dateType->id,
        'is_active' => false,
    ]);
    expect(Setting::getValue('max_titles'))->toBe('10');
    expect(Setting::getValue('max_licenses'))->toBe('7');
});

test('updating settings with invalid data returns errors', function () {
    // Issue #379: Only Admin and Group Leader can access Editor Settings
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    // Use firstOrCreate since migration may have already created MainTitle
    $title = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'is_active' => true, 'is_elmo_active' => true]
    );
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
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
                ['id' => $right->id, 'active' => true, 'elmo_active' => false],
            ],
            'languages' => [
                ['id' => $language->id, 'active' => true, 'elmo_active' => false],
            ],
            'dateTypes' => [
                ['id' => $dateType->id, 'active' => true],
            ],
            'maxTitles' => 0,
            'maxLicenses' => 7,
        ]);

    $response->assertSessionHasErrors('maxTitles')
        ->assertRedirect(route('settings'));
});
