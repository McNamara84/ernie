<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cache', function (Blueprint $table) {
            $table->bigInteger('expiration')->change();
        });

        Schema::table('cache_locks', function (Blueprint $table) {
            $table->bigInteger('expiration')->change();
        });
    }

    public function down(): void
    {
        Schema::table('cache', function (Blueprint $table) {
            $table->integer('expiration')->change();
        });

        Schema::table('cache_locks', function (Blueprint $table) {
            $table->integer('expiration')->change();
        });
    }
};
