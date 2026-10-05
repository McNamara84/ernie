<?php

declare(strict_types=1);

use App\Enums\EditorContext;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\Language;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Models\User;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\ThesaurusSettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('copies the actual ELMO settings once and initializes independent date settings', function (): void {
    $type = ResourceType::factory()->create(['is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => false]);
    $language = Language::factory()->create(['active' => true, 'elmo_active' => false, 'elmo_msl_active' => true]);
    $date = DateType::factory()->create(['is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => true]);
    TitleType::factory()->create(['is_elmo_active' => false]);
    DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods', 'is_elmo_active' => true]);
    Right::factory()->create(['is_elmo_active' => false]);
    ContributorType::create(['name' => 'Role', 'slug' => 'role', 'category' => 'both', 'is_elmo_active' => false]);
    RelationType::create(['name' => 'Relation', 'slug' => 'relation', 'is_elmo_active' => true]);
    IdentifierType::create(['name' => 'Identifier', 'slug' => 'identifier', 'is_elmo_active' => false]);
    $tables = ['resource_types', 'title_types', 'description_types', 'rights', 'contributor_types', 'relation_types', 'identifier_types', 'thesaurus_settings', 'pid_settings'];
    $original = [];
    foreach ($tables as $table) {
        $original[$table] = DB::table($table)->pluck('is_elmo_active', 'id')->map(fn ($value): bool => (bool) $value)->all();
    }
    $migration = require database_path('migrations/2026_10_05_000001_add_elmo_msl_editor_activation.php');
    $migration->down();
    $migration->up();
    foreach ($tables as $table) {
        expect(DB::table($table)->pluck('is_elmo_msl_active', 'id')->map(fn ($value): bool => (bool) $value)->all())->toBe($original[$table]);
    }
    expect($type->fresh()->is_elmo_msl_active)->toBeTrue()
        ->and($type->fresh()->is_active)->toBeFalse()
        ->and($language->fresh()->elmo_msl_active)->toBeFalse()
        ->and($date->fresh()->is_elmo_active)->toBeFalse()
        ->and($date->fresh()->is_elmo_msl_active)->toBeFalse();
    $type->refresh()->update(['is_elmo_msl_active' => false]);
    $this->seed(ResourceTypeSeeder::class);
    expect($type->fresh()->is_elmo_msl_active)->toBeFalse();
});

it('preserves legacy exclusions in three independent lists and restores identical lists on rollback', function (): void {
    $migration = require database_path('migrations/2026_10_05_000002_separate_editor_license_exclusions.php');
    $migration->down();
    $type = ResourceType::factory()->create();
    $right = Right::factory()->create();
    DB::table('right_resource_type_exclusions')->insert(['right_id' => $right->id, 'resource_type_id' => $type->id]);
    $migration->up();
    foreach (EditorContext::cases() as $editor) {
        expect($right->excludedResourceTypes($editor)->pluck('resource_types.id')->all())->toBe([$type->id]);
    }
    $right->excludedResourceTypes(EditorContext::ELMO_MSL)->sync([]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'independently configured');
    expect(Schema::hasColumn('right_resource_type_exclusions', 'editor'))->toBeTrue();
    $right->excludedResourceTypes(EditorContext::ELMO_MSL)->attach($type->id);
    $migration->down();
    expect(Schema::hasColumn('right_resource_type_exclusions', 'editor'))->toBeFalse()
        ->and(DB::table('right_resource_type_exclusions')->count())->toBe(1);
    $migration->up();
    $type->delete();
    expect(DB::table('right_resource_type_exclusions')->count())->toBe(0);
});

it('retains a unique exclusion per editor and supports independent relation syncs', function (): void {
    $type = ResourceType::factory()->create();
    $right = Right::factory()->create();
    foreach (EditorContext::cases() as $editor) {
        $right->excludedResourceTypes($editor)->sync([$type->id]);
        $right->excludedResourceTypes($editor)->sync([$type->id]);
    }
    expect(DB::table('right_resource_type_exclusions')->count())->toBe(3);
    expect(fn () => $right->excludedResourceTypes(EditorContext::ELMO)->attach($type->id))
        ->toThrow(QueryException::class);
});

it('preserves customized MSL keyword settings across seeding and runtime fallbacks', function (): void {
    $setting = ThesaurusSetting::where('type', 'msl_keywords')->firstOrFail();
    $setting->update(['is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    $this->seed(ThesaurusSettingSeeder::class);
    $this->actingAs(User::factory()->admin()->create())->get('/settings')->assertOk();
    expect($setting->fresh()->is_elmo_msl_active)->toBeFalse();
});
