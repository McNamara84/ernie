<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

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
        // Rollback the specific migration
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_03_25_070134_create_landing_page_files_table.php',
        ])->assertExitCode(0);

        expect(Schema::hasTable('landing_page_files'))->toBeFalse();

        // Re-apply the migration
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_03_25_070134_create_landing_page_files_table.php',
        ])->assertExitCode(0);

        expect(Schema::hasTable('landing_page_files'))->toBeTrue();
    });
});
