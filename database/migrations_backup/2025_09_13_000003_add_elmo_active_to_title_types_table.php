<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('title_types', function (Blueprint $table) {
            $table->boolean('elmo_active')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('title_types', function (Blueprint $table) {
            $table->dropColumn('elmo_active');
        });
    }
};
