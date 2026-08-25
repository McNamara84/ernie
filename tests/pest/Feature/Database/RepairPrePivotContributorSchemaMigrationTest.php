<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Models\ContributorType;
use App\Models\Person;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database');

function loadPrePivotContributorSchemaRepairMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_25_000001_repair_pre_pivot_contributor_schema.php'
    );

    return $migration;
}

it('is idempotent for the current schema and keeps its irreversible repair on down', function (): void {
    $migration = loadPrePivotContributorSchemaRepairMigration();

    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::hasColumns('contributor_types', ['category', 'is_elmo_active']))->toBeTrue()
        ->and(Schema::hasColumn('geo_locations', 'geo_type'))->toBeTrue()
        ->and(Schema::hasTable('resource_contributor_contributor_type'))->toBeTrue()
        ->and(Schema::hasColumn('resource_contributors', 'contributor_type_id'))->toBeFalse()
        ->and(Schema::hasTable('landing_page_domains'))->toBeTrue()
        ->and(Schema::hasColumns('landing_pages', ['external_domain_id', 'external_path']))->toBeTrue()
        ->and(Schema::hasTable('resource_instruments'))->toBeTrue();
});

it('upgrades and preserves a legacy single contributor type assignment', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    $type = ContributorType::create([
        'name' => 'Legacy Contributor Type',
        'slug' => 'LegacyContributorType',
        'category' => ContributorCategory::BOTH,
    ]);
    $timestamp = now();

    $contributorId = DB::table('resource_contributors')->insertGetId([
        'resource_id' => $resource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 0,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    Schema::disableForeignKeyConstraints();
    Schema::drop('resource_contributor_contributor_type');
    Schema::table('resource_contributors', function (Blueprint $table): void {
        $table->unsignedBigInteger('contributor_type_id')->nullable();
    });
    DB::table('resource_contributors')->where('id', $contributorId)->update([
        'contributor_type_id' => $type->id,
    ]);
    Schema::table('contributor_types', function (Blueprint $table): void {
        $table->dropColumn(['category', 'is_elmo_active']);
    });
    Schema::table('geo_locations', function (Blueprint $table): void {
        $table->dropColumn('geo_type');
    });
    Schema::enableForeignKeyConstraints();

    $migration = loadPrePivotContributorSchemaRepairMigration();
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('contributor_types', ['category', 'is_elmo_active']))->toBeTrue()
        ->and(Schema::hasColumn('geo_locations', 'geo_type'))->toBeTrue()
        ->and(Schema::hasColumn('resource_contributors', 'contributor_type_id'))->toBeFalse()
        ->and(DB::table('resource_contributor_contributor_type')->where([
            'resource_contributor_id' => $contributorId,
            'contributor_type_id' => $type->id,
        ])->count())->toBe(1);
});

it('recreates landing page and instrument infrastructure missing from legacy schemas', function (): void {
    Schema::disableForeignKeyConstraints();
    Schema::table('landing_pages', function (Blueprint $table): void {
        $table->dropForeign(['external_domain_id']);
    });
    Schema::table('landing_pages', function (Blueprint $table): void {
        $table->dropColumn(['external_domain_id', 'external_path']);
    });
    Schema::drop('landing_page_domains');
    Schema::drop('resource_instruments');
    Schema::enableForeignKeyConstraints();

    $migration = loadPrePivotContributorSchemaRepairMigration();
    $migration->up();

    expect(Schema::hasTable('landing_page_domains'))->toBeTrue()
        ->and(Schema::hasColumns('landing_pages', ['external_domain_id', 'external_path']))->toBeTrue()
        ->and(Schema::hasTable('resource_instruments'))->toBeTrue();
});
