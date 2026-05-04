<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

function loadCreateResourceAssessmentsTableMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_05_04_000001_create_resource_assessments_table.php');

    return $migration;
}

it('creates and drops the resource_assessments table through up and down', function (): void {
    expect(Schema::hasTable('resource_assessments'))->toBeTrue();

    $migration = loadCreateResourceAssessmentsTableMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasTable('resource_assessments'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasTable('resource_assessments'))->toBeTrue()
        ->and(Schema::hasColumns('resource_assessments', [
            'resource_id',
            'status',
            'total_score',
            'assessed_identifier',
            'error_message',
            'payload',
            'assessed_at',
        ]))->toBeTrue();
});