<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds and removes recipient delivery count columns on contact_messages', function (): void {
    $migration = require database_path('migrations/2026_05_30_000001_add_recipient_delivery_counts_to_contact_messages.php');

    expect(Schema::hasColumns('contact_messages', ['recipient_count', 'delivered_recipient_count']))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('contact_messages', 'recipient_count'))->toBeFalse()
        ->and(Schema::hasColumn('contact_messages', 'delivered_recipient_count'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('contact_messages', ['recipient_count', 'delivered_recipient_count']))->toBeTrue();
});