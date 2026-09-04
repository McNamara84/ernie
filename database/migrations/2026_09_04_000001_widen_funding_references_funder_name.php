<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen funding_references.funder_name from VARCHAR(255) to VARCHAR(500).
 *
 * Legacy IGSN DIF records use funding_agency for free-form acknowledgements
 * that can name several institutions and grants in one value. The 367-character
 * value used by the ICDP 5052 records exceeded the legacy database limit, so
 * MySQL aborted each enrichment and rolled back all metadata for 493 resources.
 * The new limit matches the existing request-validation contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_references', function (Blueprint $table): void {
            $table->string('funder_name', 500)->change();
        });
    }

    public function down(): void
    {
        // Refuse to narrow the column when that would silently truncate an
        // imported funding acknowledgement. Resolve the character-count
        // expression per supported driver and count trailing spaces on SQL
        // Server as well.
        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(funder_name)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(funder_name)',
            'sqlsrv' => "LEN(funder_name + 'x') - 1",
            default => throw new RuntimeException(
                "Unsupported database driver for funding_references.funder_name rollback: [{$driver}]."
            ),
        };

        $overflowExists = DB::table('funding_references')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereRaw("{$lengthExpression} > 255");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert funding_references.funder_name to VARCHAR(255): existing rows '
                .'contain values longer than 255 characters.'
            );
        }

        Schema::table('funding_references', function (Blueprint $table): void {
            $table->string('funder_name', 255)->change();
        });
    }
};
