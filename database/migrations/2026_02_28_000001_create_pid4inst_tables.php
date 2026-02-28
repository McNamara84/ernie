<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pid_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('display_name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->timestamps();
        });

        Schema::create('resource_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('instrument_pid', 512);
            $table->string('instrument_pid_type', 50)->default('Handle');
            $table->string('instrument_name', 1024);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
        });
    }
};
