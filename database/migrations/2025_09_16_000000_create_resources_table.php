<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('doi')->nullable();
            $table->unsignedSmallInteger('year');
            $table->foreignId('resource_type_id')
                ->constrained('resource_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('version', 50)->nullable();
            $table->foreignId('language_id')
                ->nullable()
                ->constrained('languages')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
