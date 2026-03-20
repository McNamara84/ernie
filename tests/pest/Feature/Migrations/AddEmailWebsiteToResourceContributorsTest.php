<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('add_email_website_to_resource_contributors migration', function () {
    it('adds email and website columns to resource_contributors table', function () {
        expect(Schema::hasColumn('resource_contributors', 'email'))->toBeTrue()
            ->and(Schema::hasColumn('resource_contributors', 'website'))->toBeTrue();
    });

    it('can rollback email and website columns', function () {
        // Rollback the specific migration by path
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_03_20_000001_add_email_website_to_resource_contributors.php',
        ]);

        // Table must still exist after partial rollback
        expect(Schema::hasTable('resource_contributors'))->toBeTrue()
            ->and(Schema::hasColumn('resource_contributors', 'email'))->toBeFalse()
            ->and(Schema::hasColumn('resource_contributors', 'website'))->toBeFalse();

        // Re-run migration to not break other tests
        $this->artisan('migrate');
    });
});
