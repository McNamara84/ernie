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
        Schema::create('dismissed_rors', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('ror_id', 255);
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'ror_id'], 'dismissed_rors_entity_ror_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dismissed_rors');
    }
};
