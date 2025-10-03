<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dataset_license', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')
                ->constrained('datasets')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('license_id')
                ->constrained('licenses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['dataset_id', 'license_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_license');
    }
};
