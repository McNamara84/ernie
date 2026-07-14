<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_dump_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_key', 50);
            $table->string('target_label', 100);
            $table->string('connection_name', 100);
            $table->string('database_name', 255);
            $table->string('status', 30)->index();
            $table->string('disk', 50);
            $table->string('path', 500)->nullable();
            $table->string('filename', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('server_version', 255)->nullable();
            $table->string('dump_client', 255)->nullable();
            $table->json('dump_options')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['target_key', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_dump_exports');
    }
};
