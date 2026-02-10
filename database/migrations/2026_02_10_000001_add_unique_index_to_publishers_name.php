<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a unique index on publishers.name to ensure updateOrCreate/firstOrCreate
     * operations are race-safe under concurrent requests (e.g., parallel CSV imports).
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table): void {
            $table->unique('name');
        });
    }
};
