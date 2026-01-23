<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change dates table to use VARCHAR for flexible ISO 8601 date formats.
 *
 * DataCite allows multiple date precision levels:
 * - Full date: YYYY-MM-DD (e.g., 2024-01-15)
 * - Year-month: YYYY-MM (e.g., 2024-01)
 * - Year only: YYYY (e.g., 2024)
 *
 * MySQL's DATE type only accepts YYYY-MM-DD, so we need VARCHAR
 * to support all DataCite-compliant formats including IGSN CSV uploads.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/date/
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dates', function (Blueprint $table): void {
            // Change from DATE to VARCHAR(10) to support YYYY, YYYY-MM, YYYY-MM-DD formats
            $table->string('date_value', 10)->nullable()->change();
            $table->string('start_date', 10)->nullable()->change();
            $table->string('end_date', 10)->nullable()->change();
        });
    }
};
