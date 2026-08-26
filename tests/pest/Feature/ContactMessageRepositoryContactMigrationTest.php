<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds and removes the repository contact selector column', function (): void {
    $migration = require database_path('migrations/2026_08_26_000001_add_repository_contact_type_to_contact_messages.php');

    expect(Schema::hasColumn('contact_messages', 'repository_contact_type'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('contact_messages', 'repository_contact_type'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('contact_messages', 'repository_contact_type'))->toBeTrue();
});
