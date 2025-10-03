<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dataset_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')
                ->constrained('datasets')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('title_type_id')
                ->constrained('title_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_titles');
    }
};
