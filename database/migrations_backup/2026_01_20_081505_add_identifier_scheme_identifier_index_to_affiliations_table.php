<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a composite index on identifier_scheme and identifier columns
     * to optimize dashboard statistics queries that filter ROR-identified affiliations.
     */
    public function up(): void
    {
        Schema::table('affiliations', function (Blueprint $table) {
            $table->index(['identifier_scheme', 'identifier'], 'affiliations_ror_lookup_index');
        });
    }
};
