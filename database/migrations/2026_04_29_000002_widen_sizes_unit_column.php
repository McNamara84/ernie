<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen sizes.unit from VARCHAR(50) to VARCHAR(255).
 *
 * The DataCite Metadata Schema defines `sizes` as free-text strings (see
 * https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/size/),
 * for example "Approximately 80 active stations; greater than 440MB/day.".
 * When such a value cannot be split into a "<number> <unit>" pair by the
 * DataCite import transformer, it is stored verbatim in `sizes.unit`. The
 * legacy 50-character limit caused MySQL to abort the import job with
 * SQLSTATE[22001] "Data too long for column 'unit'".
 *
 * VARCHAR(255) covers all realistic DataCite payloads while still allowing
 * the column to be indexed if ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            $table->string('unit', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Guard against silent truncation: refuse to narrow the column if any
        // existing row holds a value longer than 50 characters. Length is
        // computed in the database (LENGTH on SQLite, CHAR_LENGTH on MySQL)
        // so this guard is portable across the supported backends.
        $driver = DB::connection()->getDriverName();
        $lengthExpression = $driver === 'sqlite' ? 'LENGTH(unit)' : 'CHAR_LENGTH(unit)';

        $overflowExists = DB::table('sizes')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('unit')
                    ->whereRaw("{$lengthExpression} > 50");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert sizes.unit to VARCHAR(50): existing rows '
                .'contain values longer than 50 characters.'
            );
        }

        Schema::table('sizes', function (Blueprint $table): void {
            $table->string('unit', 50)->nullable()->change();
        });
    }
};
