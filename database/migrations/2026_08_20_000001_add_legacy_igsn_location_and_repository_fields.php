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
            $table->string('location_type', 100)->nullable();
            $table->text('location_description')->nullable();
            $table->string('country', 255)->nullable();
            $table->string('province', 255)->nullable();
            $table->string('county', 255)->nullable();
            $table->string('city', 255)->nullable();
        });

        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->string('original_archive', 255)->nullable();
            $table->string('original_archive_contact', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->dropColumn(['original_archive', 'original_archive_contact']);
        });

        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->dropColumn([
                'location_type',
                'location_description',
                'country',
                'province',
                'county',
                'city',
            ]);
        });
    }
};
