<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_dump_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('database_dump_export_id')
                ->constrained('database_dump_exports')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['database_dump_export_id', 'downloaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_dump_downloads');
    }
};
