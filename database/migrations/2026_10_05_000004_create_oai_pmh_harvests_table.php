<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oai_pmh_harvests', function (Blueprint $table): void {
            $table->id();
            $table->json('items');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::table('oai_pmh_resumption_tokens', function (Blueprint $table): void {
            $table->foreignId('harvest_id')->nullable()->constrained('oai_pmh_harvests')->cascadeOnDelete();
            $table->unsignedInteger('harvest_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oai_pmh_resumption_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('harvest_id');
            $table->dropColumn('harvest_position');
        });
        Schema::dropIfExists('oai_pmh_harvests');
    }
};
