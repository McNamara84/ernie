<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

test('landing page links contain the machine-readable kind column', function () {
    expect(Schema::hasColumn('landing_page_links', 'kind'))->toBeTrue();
});

test('landing page link kind migration can roll back and re-apply', function () {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_27_000001_add_kind_to_landing_page_links_table.php');

    $migration->down();
    expect(Schema::hasColumn('landing_page_links', 'kind'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('landing_page_links', 'kind'))->toBeTrue();
});
