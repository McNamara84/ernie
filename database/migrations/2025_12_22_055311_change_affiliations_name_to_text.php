<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
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
            DB::statement('DROP INDEX idx_affiliations_name ON affiliations');
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
            // SQLite: Just recreate the index
            Schema::table('affiliations', function (Blueprint $table) {
                $table->dropIndex('idx_affiliations_name');
            });
            Schema::table('affiliations', function (Blueprint $table) {
                $table->index('name', 'idx_affiliations_name');
            });
        } else {
            // MySQL/MariaDB: Revert to VARCHAR(255)
            DB::statement('DROP INDEX idx_affiliations_name ON affiliations');
            DB::statement('ALTER TABLE affiliations MODIFY name VARCHAR(255) NOT NULL');
            DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name)');
        }
    }
};
