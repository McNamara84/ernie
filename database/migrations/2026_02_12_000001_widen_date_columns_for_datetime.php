<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen date columns in `dates` table from VARCHAR(10) to VARCHAR(35)
     * to support ISO 8601 datetime with timezone (e.g., "2022-10-06T09:35:00+01:00").
     *
     * Previously, only date-only formats (YYYY, YYYY-MM, YYYY-MM-DD) could be stored.
     * This change enables full datetime+timezone storage as required by DataCite schema.
     *
     * @see https://github.com/McNamara84/ernie/issues/508
     */
    public function up(): void
    {
        Schema::table('dates', function (Blueprint $table): void {
            $table->string('date_value', 35)->nullable()->change();
            $table->string('start_date', 35)->nullable()->change();
            $table->string('end_date', 35)->nullable()->change();
        });
    }
};
