<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identifier_type_patterns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identifier_type_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['validation', 'detection']);
            $table->string('pattern', 500);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['identifier_type_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identifier_type_patterns');
    }
};
