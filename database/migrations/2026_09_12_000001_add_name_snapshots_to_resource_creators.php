<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_creators', function (Blueprint $table): void {
            $table->string('name_snapshot', 1000)->nullable()->after('website');
            $table->string('given_name_snapshot', 255)->nullable()->after('name_snapshot');
            $table->string('family_name_snapshot', 255)->nullable()->after('given_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('resource_creators', function (Blueprint $table): void {
            $table->dropColumn([
                'name_snapshot',
                'given_name_snapshot',
                'family_name_snapshot',
            ]);
        });
    }
};
