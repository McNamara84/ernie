<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->string('legacy_dif_schema_namespace', 255)->nullable();
            $table->json('legacy_dif_json')->nullable();
            $table->timestamp('legacy_dif_imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->dropColumn([
                'legacy_dif_schema_namespace',
                'legacy_dif_json',
                'legacy_dif_imported_at',
            ]);
        });
    }
};
