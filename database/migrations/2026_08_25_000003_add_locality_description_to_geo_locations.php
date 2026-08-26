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
            $table->text('locality_description')->nullable()->after('location_description');
        });
    }

    public function down(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->dropColumn('locality_description');
        });
    }
};
