<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->string('source', 100)->nullable()->after('citation_label');
        });
    }

    public function down(): void
    {
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
