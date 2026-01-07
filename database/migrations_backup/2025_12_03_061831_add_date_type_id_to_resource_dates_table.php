<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration depends on the date_types table existing.
     * Ensure 2025_12_03_061804_create_date_types_table.php runs first.
     */
    public function up(): void
    {
        // Verify date_types table exists (dependency check)
        if (! Schema::hasTable('date_types')) {
            throw new RuntimeException(
                'The date_types table must exist before running this migration. '
                .'Please run the create_date_types_table migration first.'
            );
        }

        // Step 1: Add the new foreign key column (nullable initially for migration)
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->foreignId('date_type_id')
                ->nullable()
                ->after('resource_id')
                ->constrained('date_types')
                ->nullOnDelete();
        });

        // Step 2: Migrate existing data - match date_type string to date_types.slug
        // Use database-agnostic approach for SQLite compatibility
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support UPDATE with JOIN, use subquery instead
            DB::statement('
                UPDATE resource_dates
                SET date_type_id = (
                    SELECT dt.id FROM date_types dt
                    WHERE dt.slug = resource_dates.date_type
                )
                WHERE date_type IS NOT NULL
            ');
        } else {
            // MySQL/MariaDB - use efficient JOIN syntax
            DB::statement('
                UPDATE resource_dates rd
                JOIN date_types dt ON rd.date_type = dt.slug
                SET rd.date_type_id = dt.id
            ');
        }

        // Step 3: Remove the old string column and update index
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->dropIndex(['resource_id', 'date_type']);
            $table->dropColumn('date_type');
            $table->index(['resource_id', 'date_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Re-add the string column
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->string('date_type', 50)->after('resource_id')->nullable();
        });

        // Step 2: Migrate data back - copy slug from date_types to date_type
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support UPDATE with JOIN, use subquery instead
            DB::statement('
                UPDATE resource_dates
                SET date_type = (
                    SELECT dt.slug FROM date_types dt
                    WHERE dt.id = resource_dates.date_type_id
                )
                WHERE date_type_id IS NOT NULL
            ');
        } else {
            // MySQL/MariaDB - use efficient JOIN syntax
            DB::statement('
                UPDATE resource_dates rd
                JOIN date_types dt ON rd.date_type_id = dt.id
                SET rd.date_type = dt.slug
            ');
        }

        // Step 3: Remove the foreign key column and restore index
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->dropIndex(['resource_id', 'date_type_id']);
            $table->dropForeign(['date_type_id']);
            $table->dropColumn('date_type_id');
            $table->index(['resource_id', 'date_type']);
        });
    }
};
