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
        Schema::table('resource_funding_references', function (Blueprint $table) {
            // Add funder_identifier_type column after funder_identifier
            // DataCite 4.6 supports: ROR, ISNI, GRID, Crossref Funder ID, Other
            $table->string('funder_identifier_type')->nullable()->after('funder_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_funding_references', function (Blueprint $table) {
            $table->dropColumn('funder_identifier_type');
        });
    }
};
