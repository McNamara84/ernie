<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('keyword', 255);
            $table->timestamps();

            $table->index(['resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_keywords');
    }
};
