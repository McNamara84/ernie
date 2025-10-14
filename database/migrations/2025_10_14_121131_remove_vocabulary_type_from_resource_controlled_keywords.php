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
        Schema::table('resource_controlled_keywords', function (Blueprint $table) {
            // Drop the vocabulary_type index first
            $table->dropIndex(['vocabulary_type']);
            
            // Then drop the vocabulary_type column
            $table->dropColumn('vocabulary_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_controlled_keywords', function (Blueprint $table) {
            // Recreate the vocabulary_type column
            $table->enum('vocabulary_type', ['science', 'platforms', 'instruments'])->after('scheme_uri');
            
            // Recreate the index
            $table->index(['vocabulary_type']);
        });
    }
};
