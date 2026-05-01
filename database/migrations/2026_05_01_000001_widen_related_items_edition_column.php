<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen related_items.edition from VARCHAR(64) to VARCHAR(255).
 *
 * The DataCite Metadata Schema 4.7 does not define a maximum length for the
 * `<edition>` element of a related item (see
 * https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relateditem/).
 * Real-world DataCite records use this field for free-form edition,
 * conference or report-version text that easily exceeds 64 characters, e.g.
 * "36th General Assembly of the European Seismological Commission, Malta,
 * ESC2018-S11-402". The legacy 64-character limit caused MySQL to abort the
 * DataCite import with SQLSTATE[22001] "Data too long for column 'edition'",
 * rolling back the entire resource transaction.
 *
 * VARCHAR(255) covers all realistic DataCite payloads while keeping the
 * column indexable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('related_items', function (Blueprint $table): void {
            $table->string('edition', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Guard against silent truncation: refuse to narrow the column if any
        // existing row holds a value longer than 64 characters. Each supported
        // driver exposes its own character-count function (sqlsrv has no
        // CHAR_LENGTH), so resolve the expression per driver instead of
        // assuming a single SQL dialect.
        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(edition)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(edition)',
            'sqlsrv' => 'LEN(edition)',
            default => throw new RuntimeException(
                "Unsupported database driver for related_items.edition rollback: [{$driver}]."
            ),
        };

        $overflowExists = DB::table('related_items')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('edition')
                    ->whereRaw("{$lengthExpression} > 64");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert related_items.edition to VARCHAR(64): existing rows '
                .'contain values longer than 64 characters.'
            );
        }

        Schema::table('related_items', function (Blueprint $table): void {
            $table->string('edition', 64)->nullable()->change();
        });
    }
};
