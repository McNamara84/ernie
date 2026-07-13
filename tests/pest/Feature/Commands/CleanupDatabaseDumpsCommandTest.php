<?php

declare(strict_types=1);

use App\Console\Commands\CleanupDatabaseDumps;
use App\Models\DatabaseDumpExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

covers(CleanupDatabaseDumps::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('deletes expired dump files and keeps active completed dumps', function (): void {
    $admin = User::factory()->admin()->create();
    $expired = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'path' => 'database-dumps/expired.sql.gz',
        'expires_at' => now()->subMinute(),
    ]);
    $active = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'path' => 'database-dumps/active.sql.gz',
        'expires_at' => now()->addHour(),
    ]);

    Storage::disk('local')->put('database-dumps/expired.sql.gz', 'expired');
    Storage::disk('local')->put('database-dumps/active.sql.gz', 'active');

    $this->artisan('database-dumps:cleanup')
        ->expectsOutput('Expired 1 database dump export(s).')
        ->assertSuccessful();

    expect($expired->refresh()->status)->toBe(DatabaseDumpExport::STATUS_EXPIRED)
        ->and($active->refresh()->status)->toBe(DatabaseDumpExport::STATUS_COMPLETED)
        ->and(Storage::disk('local')->exists('database-dumps/expired.sql.gz'))->toBeFalse()
        ->and(Storage::disk('local')->exists('database-dumps/active.sql.gz'))->toBeTrue();
});
it('continues expiring dumps when deleting one file fails', function (): void {
    $admin = User::factory()->admin()->create();
    $missingDisk = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'disk' => 'missing-database-dump-disk',
        'path' => 'database-dumps/missing-disk.sql.gz',
        'expires_at' => now()->subMinutes(2),
    ]);
    $local = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'path' => 'database-dumps/also-expired.sql.gz',
        'expires_at' => now()->subMinute(),
    ]);

    Storage::disk('local')->put('database-dumps/also-expired.sql.gz', 'expired');

    $this->artisan('database-dumps:cleanup')
        ->expectsOutput('Expired 2 database dump export(s).')
        ->assertSuccessful();

    expect($missingDisk->refresh()->status)->toBe(DatabaseDumpExport::STATUS_EXPIRED)
        ->and($local->refresh()->status)->toBe(DatabaseDumpExport::STATUS_EXPIRED)
        ->and(Storage::disk('local')->exists('database-dumps/also-expired.sql.gz'))->toBeFalse();
});
