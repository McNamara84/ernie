<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function getMslLaboratoriesThesaurusMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_21_000001_add_msl_laboratories_thesaurus.php'
    );
}

it('creates the MSL Laboratories setting with both consumers enabled', function (): void {
    $row = DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->first();

    expect($row)->not->toBeNull()
        ->and($row->display_name)->toBe('MSL Laboratories')
        ->and((bool) $row->is_active)->toBeTrue()
        ->and((bool) $row->is_elmo_active)->toBeTrue();
});

it('is idempotent and preserves an existing setting', function (): void {
    DB::table('thesaurus_settings')
        ->where('type', 'msl_laboratories')
        ->update(['is_active' => false]);

    getMslLaboratoriesThesaurusMigration()->up();

    expect(DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->count())
        ->toBe(1)
        ->and((bool) DB::table('thesaurus_settings')
            ->where('type', 'msl_laboratories')
            ->value('is_active'))->toBeFalse();
});

it('can roll back and re-apply the setting', function (): void {
    $migration = getMslLaboratoriesThesaurusMigration();
    $migration->down();

    expect(DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->exists())
        ->toBeFalse();

    $migration->up();

    expect(DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->exists())
        ->toBeTrue();
});
