<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Require the migration file and return its anonymous class instance.
 */
function getEuroSciVocThesaurusMigration(): Migration
{
    return require database_path('migrations/2026_04_16_000001_add_euroscivoc_thesaurus.php');
}

describe('add_euroscivoc_thesaurus migration', function () {
    it('seeds the euroscivoc thesaurus setting', function () {
        // The migration runs as part of RefreshDatabase, so the row should exist
        $row = DB::table('thesaurus_settings')->where('type', 'euroscivoc')->first();

        expect($row)->not->toBeNull()
            ->and($row->display_name)->toBe('European Science Vocabulary (EuroSciVoc)')
            ->and((bool) $row->is_active)->toBeTrue()
            ->and((bool) $row->is_elmo_active)->toBeTrue();
    });

    it('can rollback and re-apply the euroscivoc thesaurus setting', function () {
        $migration = getEuroSciVocThesaurusMigration();

        // Rollback
        $migration->down();

        expect(DB::table('thesaurus_settings')->where('type', 'euroscivoc')->exists())->toBeFalse();

        // Re-apply
        $migration->up();

        expect(DB::table('thesaurus_settings')->where('type', 'euroscivoc')->exists())->toBeTrue();
    });
});
