<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops and recreates the guided tour tables through the migration lifecycle', function (): void {
    $migration = require database_path('migrations/2026_05_07_000001_create_guided_tours_tables.php');

    expect(Schema::hasTable('guided_tours'))->toBeTrue()
        ->and(Schema::hasTable('user_guided_tour_assignments'))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('user_guided_tour_assignments'))->toBeFalse()
        ->and(Schema::hasTable('guided_tours'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('guided_tours'))->toBeTrue()
        ->and(Schema::hasTable('user_guided_tour_assignments'))->toBeTrue();
});