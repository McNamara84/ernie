<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dismissed_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('identifier', 2183);
            $table->foreignId('relation_type_id')->constrained('relation_types')->restrictOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        // Hash-based unique constraint to avoid MySQL key length limits with 2183-char identifier
        DB::statement('ALTER TABLE dismissed_relations ADD COLUMN identifier_hash CHAR(64) GENERATED ALWAYS AS (SHA2(identifier, 256)) STORED AFTER identifier');
        DB::statement('ALTER TABLE dismissed_relations ADD UNIQUE INDEX dismissed_relations_unique (resource_id, identifier_hash, relation_type_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dismissed_relations');
    }
};
