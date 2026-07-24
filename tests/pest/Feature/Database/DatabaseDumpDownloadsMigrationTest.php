<?php

declare(strict_types=1);

use App\Models\DatabaseDumpExport;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runDatabaseDumpDownloadsMigration(): void
{
    $migration = require database_path('migrations/2026_07_13_000002_create_database_dump_downloads_table.php');

    if (! is_object($migration) || ! method_exists($migration, 'up')) {
        throw new RuntimeException('The database dump downloads migration is invalid.');
    }

    $migration->up();
}

/**
 * @param  list<string>  $columns
 */
function databaseDumpDownloadsIndexName(array $columns): ?string
{
    $index = collect(Schema::getIndexes('database_dump_downloads'))
        ->first(fn (array $index): bool => array_values($index['columns'] ?? []) === $columns);

    if (! is_array($index)) {
        return null;
    }

    $name = $index['name'] ?? null;

    return is_string($name) ? $name : null;
}

function databaseDumpDownloadsHasForeignKey(string $column, string $foreignTable): bool
{
    return collect(Schema::getForeignKeys('database_dump_downloads'))
        ->contains(fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'] ?? [], true)
            && ($foreignKey['foreign_table'] ?? null) === $foreignTable);
}

it('uses a MySQL-safe explicit audit index and remains idempotent', function (): void {
    runDatabaseDumpDownloadsMigration();
    runDatabaseDumpDownloadsMigration();

    $indexName = databaseDumpDownloadsIndexName([
        'database_dump_export_id',
        'downloaded_at',
    ]);

    expect($indexName)->toBe('db_dump_downloads_export_downloaded_idx')
        ->and(strlen($indexName ?? ''))->toBeLessThanOrEqual(64)
        ->and(databaseDumpDownloadsHasForeignKey(
            'database_dump_export_id',
            'database_dump_exports',
        ))->toBeTrue()
        ->and(databaseDumpDownloadsHasForeignKey('user_id', 'users'))->toBeTrue();
});

it('repairs the table left behind by the failed MySQL index creation without losing audit rows', function (): void {
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->completed()->create();

    Schema::dropIfExists('database_dump_downloads');
    Schema::create('database_dump_downloads', function (Blueprint $table): void {
        $table->id();
        $table->foreignUuid('database_dump_export_id')
            ->constrained('database_dump_exports')
            ->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->timestamp('downloaded_at')->useCurrent();
        $table->timestamps();
    });

    DB::table('database_dump_downloads')->insert([
        'database_dump_export_id' => $export->id,
        'user_id' => $admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Deployment before index repair',
        'downloaded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runDatabaseDumpDownloadsMigration();
    runDatabaseDumpDownloadsMigration();

    $auditExportId = DB::table('database_dump_downloads')->value('database_dump_export_id');
    $indexName = databaseDumpDownloadsIndexName([
        'database_dump_export_id',
        'downloaded_at',
    ]);

    expect(DB::table('database_dump_downloads')->count())->toBe(1)
        ->and($auditExportId)->toBe($export->id)
        ->and($indexName)->toBe('db_dump_downloads_export_downloaded_idx')
        ->and(databaseDumpDownloadsHasForeignKey(
            'database_dump_export_id',
            'database_dump_exports',
        ))->toBeTrue()
        ->and(databaseDumpDownloadsHasForeignKey('user_id', 'users'))->toBeTrue();
});
