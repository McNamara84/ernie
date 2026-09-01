<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('igsn_operators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->text('value');
            $table->char('normalized_value_hash', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['resource_id', 'normalized_value_hash'], 'igsn_operator_resource_hash_unique');
            $table->index(['resource_id', 'position'], 'igsn_operator_resource_position_index');
        });

        Schema::create('igsn_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('scheme')->nullable();
            $table->text('value');
            $table->char('normalized_value_hash', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['resource_id', 'normalized_value_hash'], 'igsn_method_resource_hash_unique');
            $table->index(['resource_id', 'position'], 'igsn_method_resource_position_index');
        });

        Schema::create('igsn_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('start_value')->nullable();
            $table->string('end_value')->nullable();
            $table->string('unit')->nullable();
            $table->string('end_unit')->nullable();
            $table->char('normalized_value_hash', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['resource_id', 'type', 'normalized_value_hash'], 'igsn_measure_resource_type_hash_unique');
            $table->index(['resource_id', 'type', 'position'], 'igsn_measure_resource_type_position_index');
        });

        Schema::create('igsn_metadata_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('value');
            $table->char('normalized_value_hash', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['resource_id', 'type', 'normalized_value_hash'], 'igsn_meta_value_resource_type_hash_unique');
            $table->index(['resource_id', 'type', 'position'], 'igsn_meta_value_resource_type_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igsn_metadata_values');
        Schema::dropIfExists('igsn_measurements');
        Schema::dropIfExists('igsn_methods');
        Schema::dropIfExists('igsn_operators');
    }
};
