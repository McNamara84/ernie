<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds all resource-specific creator name snapshot columns', function (): void {
    expect(Schema::hasColumns('resource_creators', [
        'name_snapshot',
        'given_name_snapshot',
        'family_name_snapshot',
    ]))->toBeTrue();
});

it('can roll back and re-apply the creator name snapshot migration', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_12_000001_add_name_snapshots_to_resource_creators.php');

    $migration->down();

    expect(Schema::hasColumn('resource_creators', 'name_snapshot'))->toBeFalse()
        ->and(Schema::hasColumn('resource_creators', 'given_name_snapshot'))->toBeFalse()
        ->and(Schema::hasColumn('resource_creators', 'family_name_snapshot'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('resource_creators', [
        'name_snapshot',
        'given_name_snapshot',
        'family_name_snapshot',
    ]))->toBeTrue();
});
