<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relation_types', function (Blueprint $table) {
            $table->boolean('is_elmo_active')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('relation_types', function (Blueprint $table) {
            $table->dropColumn('is_elmo_active');
        });
    }
};
