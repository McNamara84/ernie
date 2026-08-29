<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->index(['point_latitude', 'point_longitude'], 'geo_locations_portal_point_idx');
            $table->index(
                ['in_polygon_point_latitude', 'in_polygon_point_longitude'],
                'geo_locations_portal_polygon_point_idx',
            );
            $table->index(
                ['south_bound_latitude', 'north_bound_latitude'],
                'geo_locations_portal_box_lat_idx',
            );
            $table->index(
                ['west_bound_longitude', 'east_bound_longitude'],
                'geo_locations_portal_box_lng_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->dropIndex('geo_locations_portal_point_idx');
            $table->dropIndex('geo_locations_portal_polygon_point_idx');
            $table->dropIndex('geo_locations_portal_box_lat_idx');
            $table->dropIndex('geo_locations_portal_box_lng_idx');
        });
    }
};
