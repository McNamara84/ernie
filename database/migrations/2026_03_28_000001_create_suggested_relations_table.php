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
        Schema::create('suggested_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('identifier', 2183);
            $table->foreignId('identifier_type_id')->constrained('identifier_types')->restrictOnDelete();
            $table->foreignId('relation_type_id')->constrained('relation_types')->restrictOnDelete();
            $table->string('source');
            $table->string('source_title')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_publisher')->nullable();
            $table->string('source_publication_date')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamps();
        });

        // MySQL needs a hash-based unique constraint because 2183-char identifier exceeds key length limit
        // SQLite has no key length limit and can index the identifier directly
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE suggested_relations ADD COLUMN identifier_hash CHAR(64) GENERATED ALWAYS AS (SHA2(identifier, 256)) STORED AFTER identifier');
            DB::statement('ALTER TABLE suggested_relations ADD UNIQUE INDEX suggested_relations_unique (resource_id, identifier_hash, relation_type_id)');
        } else {
            Schema::table('suggested_relations', function (Blueprint $table): void {
                $table->unique(['resource_id', 'identifier', 'relation_type_id'], 'suggested_relations_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_relations');
    }
};
