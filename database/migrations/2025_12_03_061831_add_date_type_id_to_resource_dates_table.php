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
        // Step 1: Add the new foreign key column (nullable initially for migration)
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->foreignId('date_type_id')
                ->nullable()
                ->after('resource_id')
                ->constrained('date_types')
                ->nullOnDelete();
        });

        // Step 2: Migrate existing data - match date_type string to date_types.slug
        DB::statement('
            UPDATE resource_dates rd
            JOIN date_types dt ON rd.date_type = dt.slug
            SET rd.date_type_id = dt.id
        ');

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
        DB::statement('
            UPDATE resource_dates rd
            JOIN date_types dt ON rd.date_type_id = dt.id
            SET rd.date_type = dt.slug
        ');

        // Step 3: Remove the foreign key column and restore index
        Schema::table('resource_dates', function (Blueprint $table) {
            $table->dropIndex(['resource_id', 'date_type_id']);
            $table->dropForeign(['date_type_id']);
            $table->dropColumn('date_type_id');
            $table->index(['resource_id', 'date_type']);
        });
    }
};
