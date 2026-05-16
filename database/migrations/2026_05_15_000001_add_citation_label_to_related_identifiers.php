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
            // MEDIUMTEXT keeps the 65,535-character validation ceiling safe
            // under utf8mb4, where TEXT would otherwise cap at 65,535 bytes.
            $table->mediumText('citation_label')->nullable()->after('relation_type_information');
        });
    }

    public function down(): void
    {
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->dropColumn('citation_label');
        });
    }
};