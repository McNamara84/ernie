<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add elevation fields to geo_locations table for IGSN support.
 *
 * Elevation is optional and primarily used for physical samples (IGSNs)
 * where altitude/depth information is relevant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->decimal('elevation', 10, 2)->nullable()->after('point_latitude');
            $table->string('elevation_unit', 50)->nullable()->after('elevation');
        });
    }
};
