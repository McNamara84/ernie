<?php

use App\Models\DateType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
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

test('thesaurus settings are auto-created when missing', function () {
    // Regression test: Ensure thesauri are automatically created if they don't exist
    // This fixes a bug where thesauri weren't displayed after the seeder wasn't run
    $user = User::factory()->admin()->create();
    ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]);
    TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]);
    Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true]);
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => (string) Setting::DEFAULT_LIMIT]);
    Setting::create(['key' => 'max_licenses', 'value' => (string) Setting::DEFAULT_LIMIT]);

    // Verify no thesaurus settings exist initially
    expect(ThesaurusSetting::count())->toBe(0);

    $this->actingAs($user);
    withoutVite();

    // Access the settings page - this should auto-create thesaurus settings
    $response = $this->get(route('settings'))->assertOk();

    // Verify all three thesaurus settings were created
    expect(ThesaurusSetting::count())->toBe(3);

    $this->assertDatabaseHas('thesaurus_settings', [
        'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
        'display_name' => 'GCMD Science Keywords',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    $this->assertDatabaseHas('thesaurus_settings', [
        'type' => ThesaurusSetting::TYPE_PLATFORMS,
        'display_name' => 'GCMD Platforms',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    $this->assertDatabaseHas('thesaurus_settings', [
        'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
        'display_name' => 'GCMD Instruments',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    // Verify thesauri are returned in the response
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/index')
        ->has('thesauri', 3)
        ->where('thesauri.0.type', ThesaurusSetting::TYPE_SCIENCE_KEYWORDS)
        ->where('thesauri.0.displayName', 'GCMD Science Keywords')
        ->where('thesauri.1.type', ThesaurusSetting::TYPE_PLATFORMS)
        ->where('thesauri.2.type', ThesaurusSetting::TYPE_INSTRUMENTS)
    );
});

// Issue #365: Select / Deselect All in Editor Settings
// These tests verify the backend correctly handles bulk-toggled payloads (all active / all inactive).

test('admin can set all resource types to inactive at once', function () {
    $user = User::factory()->admin()->create();
    $rt1 = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $rt2 = ResourceType::create(['name' => 'Collection', 'slug' => 'Collection', 'is_active' => true, 'is_elmo_active' => true]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $rt1->id, 'name' => 'Dataset', 'active' => false, 'elmo_active' => false],
            ['id' => $rt2->id, 'name' => 'Collection', 'active' => false, 'elmo_active' => false],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => true, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => true],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('resource_types', ['id' => $rt1->id, 'is_active' => false, 'is_elmo_active' => false]);
    $this->assertDatabaseHas('resource_types', ['id' => $rt2->id, 'is_active' => false, 'is_elmo_active' => false]);
});

test('admin can set all resource types to active at once', function () {
    $user = User::factory()->admin()->create();
    $rt1 = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => false, 'is_elmo_active' => false]);
    $rt2 = ResourceType::create(['name' => 'Collection', 'slug' => 'Collection', 'is_active' => false, 'is_elmo_active' => false]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $rt1->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
            ['id' => $rt2->id, 'name' => 'Collection', 'active' => true, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => true, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => true],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('resource_types', ['id' => $rt1->id, 'is_active' => true, 'is_elmo_active' => true]);
    $this->assertDatabaseHas('resource_types', ['id' => $rt2->id, 'is_active' => true, 'is_elmo_active' => true]);
});

test('admin can set all licenses to inactive at once', function () {
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $lic1 = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $lic2 = Right::create(['identifier' => 'CC0', 'name' => 'Public Domain', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $lic1->id, 'active' => false, 'elmo_active' => false, 'excluded_resource_type_ids' => []],
            ['id' => $lic2->id, 'active' => false, 'elmo_active' => false, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => true, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => true],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('rights', ['id' => $lic1->id, 'is_active' => false, 'is_elmo_active' => false]);
    $this->assertDatabaseHas('rights', ['id' => $lic2->id, 'is_active' => false, 'is_elmo_active' => false]);
});

test('admin can set all languages to inactive at once', function () {
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $lang1 = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $lang2 = Language::create(['code' => 'de', 'name' => 'German', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $lang1->id, 'active' => false, 'elmo_active' => false],
            ['id' => $lang2->id, 'active' => false, 'elmo_active' => false],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => true],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('languages', ['id' => $lang1->id, 'active' => false, 'elmo_active' => false]);
    $this->assertDatabaseHas('languages', ['id' => $lang2->id, 'active' => false, 'elmo_active' => false]);
});

test('admin can set all title types to inactive at once', function () {
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $tt1 = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $tt2 = TitleType::create(['name' => 'Alternative', 'slug' => 'Alternative', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dateType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $tt1->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => false, 'elmo_active' => false],
            ['id' => $tt2->id, 'name' => 'Alternative', 'slug' => 'Alternative', 'active' => false, 'elmo_active' => false],
        ],
        'licenses' => [
            ['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => true, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dateType->id, 'active' => true],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('title_types', ['id' => $tt1->id, 'is_active' => false, 'is_elmo_active' => false]);
    $this->assertDatabaseHas('title_types', ['id' => $tt2->id, 'is_active' => false, 'is_elmo_active' => false]);
});

test('admin can set all date types to inactive at once', function () {
    $user = User::factory()->admin()->create();
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true]);
    $title = TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    $right = Right::create(['identifier' => 'MIT', 'name' => 'MIT License', 'is_active' => true, 'is_elmo_active' => true]);
    $language = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    $dt1 = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $dt2 = DateType::create(['name' => 'Accepted', 'slug' => 'Accepted', 'is_active' => true]);
    Setting::create(['key' => 'max_titles', 'value' => '5']);
    Setting::create(['key' => 'max_licenses', 'value' => '5']);
    $this->actingAs($user);

    $this->post(route('settings.update'), [
        'resourceTypes' => [
            ['id' => $type->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true],
        ],
        'titleTypes' => [
            ['id' => $title->id, 'name' => 'Main Title', 'slug' => 'MainTitle', 'active' => true, 'elmo_active' => true],
        ],
        'licenses' => [
            ['id' => $right->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []],
        ],
        'languages' => [
            ['id' => $language->id, 'active' => true, 'elmo_active' => true],
        ],
        'dateTypes' => [
            ['id' => $dt1->id, 'active' => false],
            ['id' => $dt2->id, 'active' => false],
        ],
        'maxTitles' => 5,
        'maxLicenses' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('date_types', ['id' => $dt1->id, 'is_active' => false]);
    $this->assertDatabaseHas('date_types', ['id' => $dt2->id, 'is_active' => false]);
});
