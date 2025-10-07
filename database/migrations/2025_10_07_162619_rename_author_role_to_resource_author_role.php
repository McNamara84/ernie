<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('author_role', 'resource_author_role');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('resource_author_role', 'author_role');
    }
};
