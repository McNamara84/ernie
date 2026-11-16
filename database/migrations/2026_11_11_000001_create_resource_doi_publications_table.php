<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['igsn_registration_runs', 'datacite_url_update_runs'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('datacite_context_fingerprint', 64)->nullable();
            });
        }
        Schema::table('igsn_registration_items', fn (Blueprint $table) => $table->string('approval_fingerprint', 64)->nullable());
        Schema::create('resource_doi_publications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('active_resource_id')->nullable()->unique();
            $table->string('operation', 32)->default('doi_publication');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('test_mode');
            $table->string('endpoint');
            $table->string('repository');
            $table->string('prefix', 64);
            $table->string('doi')->nullable();
            $table->text('target_url');
            $table->string('metadata_hash', 64);
            $table->json('payload');
            $table->string('previous_state', 32)->nullable();
            $table->string('status', 40)->default('prepared');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'test_mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_doi_publications');
        Schema::table('igsn_registration_items', fn (Blueprint $table) => $table->dropColumn('approval_fingerprint'));
        foreach (['igsn_registration_runs', 'datacite_url_update_runs'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('datacite_context_fingerprint'));
        }
    }
};
