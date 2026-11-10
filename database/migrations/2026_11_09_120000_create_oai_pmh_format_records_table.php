<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oai_pmh_format_records', function (Blueprint $table): void {
            $table->id();
            $table->string('metadata_prefix', 32);
            $table->string('oai_identifier', 700);
            $table->string('doi', 600);
            // Deliberately no cascading FK: harvest history outlives the Resource.
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->string('status', 8);
            $table->timestamp('first_available_at');
            $table->timestamp('datestamp')->index();
            $table->json('sets');
            $table->string('resource_type')->nullable();
            $table->boolean('is_igsn')->default(false);
            $table->unsignedInteger('export_revision');
            $table->char('fingerprint', 64)->nullable();
            $table->unique(['metadata_prefix', 'oai_identifier'], 'oai_format_identity_unique');
            $table->index(['metadata_prefix', 'status', 'datestamp'], 'oai_format_harvest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oai_pmh_format_records');
    }
};
