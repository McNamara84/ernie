<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_resource', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('license_id')
                ->constrained('licenses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['resource_id', 'license_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_resource');
    }
};
