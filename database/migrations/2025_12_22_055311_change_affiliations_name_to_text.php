<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function dropIndexIfExistsMySql(string $table, string $indexName): void
    {
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();

        if ($exists) {
            DB::statement("DROP INDEX {$indexName} ON {$table}");
        }
    }

    /**
     * Run the migrations.
     *
     * Supported databases: MySQL/MariaDB, SQLite
     *
    * Note: PostgreSQL is not currently supported by this migration. The raw SQL
    * statements use MySQL-specific syntax (DROP INDEX ... ON ..., MODIFY column).
    * If PostgreSQL support is needed, add a condition for
     * $driver === 'pgsql' with equivalent PostgreSQL syntax:
     * - ALTER TABLE ... ALTER COLUMN ... TYPE TEXT;
     * - DROP INDEX IF EXISTS idx_affiliations_name;
     * - CREATE INDEX idx_affiliations_name ON affiliations (name);
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support modifying columns, but TEXT is the default for strings anyway
            // Just drop and recreate the index (SQLite syntax differs from MySQL)
            try {
                Schema::table('affiliations', function (Blueprint $table) {
                    $table->dropIndex('idx_affiliations_name');
                });
            } catch (\Throwable) {
                // ignore
            }
            // SQLite doesn't support prefix indexes, so just recreate without prefix
            Schema::table('affiliations', function (Blueprint $table) {
                $table->index('name', 'idx_affiliations_name');
            });
        } else {
            // MySQL/MariaDB: Need to drop index, modify column, recreate with prefix
            $this->dropIndexIfExistsMySql('affiliations', 'idx_affiliations_name');
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
            $this->dropIndexIfExistsMySql('affiliations', 'idx_affiliations_name');
            DB::statement('ALTER TABLE affiliations MODIFY name VARCHAR(255) NOT NULL');
            DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name)');
        }
    }
};
