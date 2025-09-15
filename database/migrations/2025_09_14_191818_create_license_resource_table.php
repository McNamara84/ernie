<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_resource', function (Blueprint $table) {
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->primary(['license_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_resource');
    }
};
