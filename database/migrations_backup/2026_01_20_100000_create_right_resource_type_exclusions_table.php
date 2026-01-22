<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('right_id')
                ->constrained('rights')
                ->cascadeOnDelete();
            $table->foreignId('resource_type_id')
                ->constrained('resource_types')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['right_id', 'resource_type_id'], 'unique_exclusion');
        });
    }
};
