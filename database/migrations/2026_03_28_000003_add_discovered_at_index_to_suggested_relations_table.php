<?php

declare(strict_types=1);

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
        Schema::table('suggested_relations', function (Blueprint $table): void {
            $table->index(['discovered_at', 'id'], 'suggested_relations_discovered_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_relations', function (Blueprint $table): void {
            $table->dropIndex('suggested_relations_discovered_at_id_index');
        });
    }
};
