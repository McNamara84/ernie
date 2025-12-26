<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check if an index exists on a table.
     *
     * Note: This method uses MySQL/MariaDB-specific INFORMATION_SCHEMA queries.
     * It is only called in the MySQL/MariaDB code path (not for SQLite).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select(
            'SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );

        return $result[0]->cnt > 0;
    }

    /**
     * Run the migrations.
     *
     * Supported databases: MySQL/MariaDB
     *
     * Note: SQLite support is limited - the column type change is a no-op since SQLite
     * uses dynamic typing, and the index is simply recreated. The indexExists() method
     * is MySQL-specific but is only called in the MySQL code path.
     *
     * PostgreSQL is not currently supported. If needed, add a condition for
     * $driver === 'pgsql' with equivalent PostgreSQL syntax.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support modifying columns, but TEXT is the default for strings anyway
            // Just drop and recreate the index (SQLite syntax differs from MySQL)
            Schema::table('affiliations', function (Blueprint $table) {
                $table->dropIndex('idx_affiliations_name');
            });
            // SQLite doesn't support prefix indexes, so just recreate without prefix
            Schema::table('affiliations', function (Blueprint $table) {
                $table->index('name', 'idx_affiliations_name');
            });
        } else {
            // MySQL/MariaDB: Need to drop index, modify column, recreate with prefix
            // Check if index exists before dropping (MySQL doesn't support IF EXISTS for DROP INDEX)
            if ($this->indexExists('affiliations', 'idx_affiliations_name')) {
                DB::statement('DROP INDEX idx_affiliations_name ON affiliations');
            }
            DB::statement('ALTER TABLE affiliations MODIFY name TEXT NOT NULL');
            DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name(191))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: this migration is effectively a no-op for the schema.
            // The 'name' column type is already compatible and the index definition
            // does not change, so there is no meaningful "previous state" to restore.
            // We intentionally leave this as a no-op to document that the migration
            // is idempotent rather than strictly reversible on SQLite.
            return;
        } else {
            // MySQL/MariaDB: Revert to VARCHAR(255)
            // Note: Original schema used a regular index on VARCHAR(255), which doesn't require a prefix.
            // This correctly restores the previous state.
            // Check if index exists before dropping (MySQL doesn't support IF EXISTS for DROP INDEX)
            if ($this->indexExists('affiliations', 'idx_affiliations_name')) {
                DB::statement('DROP INDEX idx_affiliations_name ON affiliations');
            }
            DB::statement('ALTER TABLE affiliations MODIFY name VARCHAR(255) NOT NULL');
            DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name)');
        }
    }
};
