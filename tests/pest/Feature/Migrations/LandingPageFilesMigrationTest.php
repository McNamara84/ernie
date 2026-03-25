<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Require the migration file and return its anonymous class instance.
 */
function getLandingPageFilesMigration(): Migration
{
    return require database_path('migrations/2026_03_25_070134_create_landing_page_files_table.php');
}

describe('landing_page_files migration', function () {
    it('creates the landing_page_files table with correct columns', function () {
        expect(Schema::hasTable('landing_page_files'))->toBeTrue();

        expect(Schema::hasColumns('landing_page_files', [
            'id',
            'landing_page_id',
            'url',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    it('can rollback and re-apply the migration', function () {
        $migration = getLandingPageFilesMigration();

        // Rollback by calling down() directly
        $migration->down();

        expect(Schema::hasTable('landing_page_files'))->toBeFalse();

        // Re-apply by calling up() directly
        $migration->up();

        expect(Schema::hasTable('landing_page_files'))->toBeTrue();
    });
});
