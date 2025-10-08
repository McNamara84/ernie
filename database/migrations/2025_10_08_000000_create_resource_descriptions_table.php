<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('description_type', 50);
            $table->text('description');
            $table->timestamps();

            $table->index(['resource_id', 'description_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_descriptions');
    }
};
