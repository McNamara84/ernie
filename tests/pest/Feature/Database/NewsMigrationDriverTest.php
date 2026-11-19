<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('generates MySQL-compatible news tables for the configured driver', function (string $driver): void {
    $originalConnection = DB::getDefaultConnection();
    $originalConfig = config('database.connections.news_migration_driver');
    config(['database.connections.news_migration_driver' => [
        'driver' => $driver,
        'database' => 'ernie_test',
        'prefix' => '',
    ]]);

    try {
        DB::setDefaultConnection('news_migration_driver');
        // Inspect the configured Laravel driver without opening another database server.
        $queries = DB::connection()->pretend(function (): void {
            $migration = require database_path('migrations/2026_11_18_000001_create_news_tables.php');
            $migration->up();
        });

        expect($queries[0]['query'])->toContain(
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'created_by_user_id BIGINT UNSIGNED',
            'updated_by_user_id BIGINT UNSIGNED',
            'ENGINE=InnoDB',
        )->not->toContain('AUTOINCREMENT');
        expect($queries[2]['query'])->toContain('active_news_id BIGINT UNSIGNED', 'ENGINE=InnoDB');
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('news_migration_driver');
        config(['database.connections.news_migration_driver' => $originalConfig]);
    }
})->with(['mysql', 'mariadb']);
