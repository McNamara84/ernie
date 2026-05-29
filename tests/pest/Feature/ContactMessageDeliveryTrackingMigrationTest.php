<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds and removes the delivery tracking columns on contact_messages', function (): void {
    $migration = require database_path('migrations/2026_05_29_120000_add_delivery_tracking_to_contact_messages.php');

    expect(Schema::hasColumns('contact_messages', ['queued_at', 'failed_at', 'failure_reason']))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('contact_messages', 'queued_at'))->toBeFalse()
        ->and(Schema::hasColumn('contact_messages', 'failed_at'))->toBeFalse()
        ->and(Schema::hasColumn('contact_messages', 'failure_reason'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('contact_messages', ['queued_at', 'failed_at', 'failure_reason']))->toBeTrue();
});