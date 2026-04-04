<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('landing_page_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')
                ->constrained('landing_pages')
                ->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('label', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['landing_page_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_links');
    }
};
