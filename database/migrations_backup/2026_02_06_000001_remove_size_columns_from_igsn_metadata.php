<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Remove redundant size/size_unit columns from igsn_metadata table.
     * 2. Add structured columns (numeric_value, unit, type) to sizes table
     *    for 3NF-compliant storage of IGSN size data.
     * 3. Remove the value column from sizes table (export string is now
     *    built dynamically from numeric_value + unit).
     *
     * @see https://github.com/McNamara84/ernie/issues/488
     */
    public function up(): void
    {
        // Remove redundant columns from igsn_metadata
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $columnsToDrop = collect(['size', 'size_unit'])
                ->filter(fn (string $column): bool => Schema::hasColumn('igsn_metadata', $column))
                ->all();

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });

        // Add structured columns to sizes table for 3NF compliance
        Schema::table('sizes', function (Blueprint $table): void {
            if (! Schema::hasColumn('sizes', 'numeric_value')) {
                $table->decimal('numeric_value', 12, 4)->nullable()->after('resource_id');
            }
            if (! Schema::hasColumn('sizes', 'unit')) {
                $table->string('unit', 50)->nullable()->after('numeric_value');
            }
            if (! Schema::hasColumn('sizes', 'type')) {
                $table->string('type', 100)->nullable()->after('unit');
            }
        });

        // Remove the now-redundant value column
        if (Schema::hasColumn('sizes', 'value')) {
            Schema::table('sizes', function (Blueprint $table): void {
                $table->dropColumn('value');
            });
        }
    }
};
