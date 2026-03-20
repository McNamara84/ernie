<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Require the migration file and return its anonymous class instance.
 */
function getEmailWebsiteMigration(): Migration
{
    return require database_path('migrations/2026_03_20_000001_add_email_website_to_resource_contributors.php');
}

describe('add_email_website_to_resource_contributors migration', function () {
    it('adds email and website columns to resource_contributors table', function () {
        expect(Schema::hasColumn('resource_contributors', 'email'))->toBeTrue()
            ->and(Schema::hasColumn('resource_contributors', 'website'))->toBeTrue();
    });

    it('can rollback and re-apply email and website columns', function () {
        $migration = getEmailWebsiteMigration();

        // Rollback by calling down() directly
        $migration->down();

        expect(Schema::hasTable('resource_contributors'))->toBeTrue()
            ->and(Schema::hasColumn('resource_contributors', 'email'))->toBeFalse()
            ->and(Schema::hasColumn('resource_contributors', 'website'))->toBeFalse();

        // Re-apply by calling up() directly
        $migration->up();

        expect(Schema::hasColumn('resource_contributors', 'email'))->toBeTrue()
            ->and(Schema::hasColumn('resource_contributors', 'website'))->toBeTrue();
    });
});
