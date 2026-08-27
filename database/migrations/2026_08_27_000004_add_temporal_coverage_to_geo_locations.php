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
            $table->date('start_date')->nullable()->after('place');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('start_time', 8)->nullable()->after('end_date');
            $table->string('end_time', 8)->nullable()->after('start_time');
            $table->string('timezone', 100)->nullable()->after('end_time');
            $table->unsignedInteger('position')->default(0)->after('timezone');
            $table->index(['resource_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->dropIndex(['resource_id', 'position']);
            $table->dropColumn([
                'start_date',
                'end_date',
                'start_time',
                'end_time',
                'timezone',
                'position',
            ]);
        });
    }
};
