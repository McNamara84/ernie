<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('date_type', 50);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('date_information')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'date_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_dates');
    }
};
