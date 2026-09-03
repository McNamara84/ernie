<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->index('material', 'igsn_metadata_material_idx');
        });

        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->index(['classification_type', 'value', 'resource_id'], 'igsn_class_type_value_resource_idx');
        });

        Schema::table('igsn_geological_ages', function (Blueprint $table): void {
            $table->index(['value', 'resource_id'], 'igsn_geo_ages_value_resource_idx');
        });

        Schema::table('igsn_geological_units', function (Blueprint $table): void {
            $table->index(['value', 'resource_id'], 'igsn_geo_units_value_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::table('igsn_geological_units', function (Blueprint $table): void {
            $table->dropIndex('igsn_geo_units_value_resource_idx');
        });

        Schema::table('igsn_geological_ages', function (Blueprint $table): void {
            $table->dropIndex('igsn_geo_ages_value_resource_idx');
        });

        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->dropIndex('igsn_class_type_value_resource_idx');
        });

        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->dropIndex('igsn_metadata_material_idx');
        });
    }
};
