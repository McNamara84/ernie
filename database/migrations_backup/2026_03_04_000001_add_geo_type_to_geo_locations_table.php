<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit geo_type column to geo_locations table.
     *
     * Previously the type (point, box, polygon) was determined implicitly
     * by checking which coordinate columns had values. This migration adds
     * an explicit enum column and backfills existing rows.
     */
    public function up(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->string('geo_type', 10)->nullable()->after('resource_id');
        });

        // Backfill existing rows based on implicit type detection
        // Order matters: polygon check first (most specific), then box, then point
        DB::table('geo_locations')
            ->whereNotNull('polygon_points')
            ->update(['geo_type' => 'polygon']);

        DB::table('geo_locations')
            ->whereNull('geo_type')
            ->whereNotNull('west_bound_longitude')
            ->whereNotNull('east_bound_longitude')
            ->whereNotNull('south_bound_latitude')
            ->whereNotNull('north_bound_latitude')
            ->update(['geo_type' => 'box']);

        DB::table('geo_locations')
            ->whereNull('geo_type')
            ->whereNotNull('point_longitude')
            ->whereNotNull('point_latitude')
            ->update(['geo_type' => 'point']);
    }
};
