<?php

declare(strict_types=1);

use App\Models\DatabaseDumpDownload;
use App\Models\DatabaseDumpExport;
use App\Models\User;
use Database\Factories\DatabaseDumpExportFactory;

covers(DatabaseDumpExport::class, DatabaseDumpDownload::class, DatabaseDumpExportFactory::class);

it('scopes active exports for a user', function (): void {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $pending = DatabaseDumpExport::factory()->for($admin)->create();
    $running = DatabaseDumpExport::factory()->for($admin)->running()->create();
    DatabaseDumpExport::factory()->for($admin)->completed()->create();
    DatabaseDumpExport::factory()->for($otherAdmin)->running()->create();

    expect(DatabaseDumpExport::query()->activeForUser($admin->id)->pluck('id')->all())
        ->toEqualCanonicalizing([$pending->id, $running->id]);
});

it('knows whether exports are expired or downloadable', function (): void {
    $completed = DatabaseDumpExport::factory()->completed()->create([
        'expires_at' => now()->addHour(),
        'path' => 'database-dumps/ready.sql.gz',
    ]);
    $expired = DatabaseDumpExport::factory()->expired()->create();
    $pending = DatabaseDumpExport::factory()->create();

    expect($completed->isExpired())->toBeFalse()
        ->and($completed->isDownloadable())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isDownloadable())->toBeFalse()
        ->and($pending->isDownloadable())->toBeFalse();
});

it('relates exports, downloads, and users', function (): void {
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->completed()->create();
    $download = DatabaseDumpDownload::query()->create([
        'database_dump_export_id' => $export->id,
        'user_id' => $admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'downloaded_at' => now(),
    ]);

    expect($export->user->is($admin))->toBeTrue()
        ->and($export->downloads)->toHaveCount(1)
        ->and($download->export->is($export))->toBeTrue()
        ->and($download->user->is($admin))->toBeTrue();
});

it('provides factory states for running, completed, and expired exports', function (): void {
    $running = DatabaseDumpExport::factory()->running()->create();
    $completed = DatabaseDumpExport::factory()->completed()->create();
    $expired = DatabaseDumpExport::factory()->expired()->create();

    expect($running->status)->toBe(DatabaseDumpExport::STATUS_RUNNING)
        ->and($running->started_at)->not->toBeNull()
        ->and($completed->status)->toBe(DatabaseDumpExport::STATUS_COMPLETED)
        ->and($completed->finished_at)->not->toBeNull()
        ->and($completed->path)->toBe('database-dumps/test.sql.gz')
        ->and($expired->status)->toBe(DatabaseDumpExport::STATUS_COMPLETED)
        ->and($expired->expires_at?->isPast())->toBeTrue();
});
