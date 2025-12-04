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
        // Check if columns already exist before adding
        if (! Schema::hasColumn('resource_coverages', 'type')) {
            Schema::table('resource_coverages', function (Blueprint $table) {
                // Add type discriminator column for coverage type (point, box, polygon)
                $table->enum('type', ['point', 'box', 'polygon'])
                    ->default('point')
                    ->after('resource_id');
            });
        }

        if (! Schema::hasColumn('resource_coverages', 'polygon_points')) {
            Schema::table('resource_coverages', function (Blueprint $table) {
                // Add JSON column for polygon coordinates
                // Structure: [{"lat": 41.090, "lon": -71.032}, {...}]
                $table->json('polygon_points')->nullable()->after('lon_max');
            });
        }

        // Migrate existing data: set type based on existing coordinates
        // Only update records where type is still default 'point' but coordinates suggest otherwise
        // Point: when lat_max and lon_max are NULL
        // Box: when lat_max or lon_max are NOT NULL
        DB::table('resource_coverages')
            ->where('type', 'point')
            ->whereNull('lat_max')
            ->whereNull('lon_max')
            ->whereNotNull('lat_min')
            ->update(['type' => 'point']);

        DB::table('resource_coverages')
            ->where('type', 'point')
            ->where(function ($query) {
                $query->whereNotNull('lat_max')
                    ->orWhereNotNull('lon_max');
            })
            ->update(['type' => 'box']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_coverages', function (Blueprint $table) {
            $table->dropColumn(['type', 'polygon_points']);
        });
    }
};
