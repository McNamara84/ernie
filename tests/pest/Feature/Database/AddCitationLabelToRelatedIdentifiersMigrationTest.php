<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('database');

function loadAddCitationLabelMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_05_15_000001_add_citation_label_to_related_identifiers.php');

    return $migration;
}

it('drops and re-adds the citation_label column on related_identifiers', function (): void {
    $migration = loadAddCitationLabelMigration();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeTrue();
});